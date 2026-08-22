const http=require('http');
const crypto=require('crypto');
const dns=require('dns').promises;
const net=require('net');
const fs=require('fs');
const path=require('path');
const {spawn,execFile}=require('child_process');

const HOST=process.env.TUBETV_WORKER_BIND||'127.0.0.1';
const PORT=Number(process.env.TUBETV_WORKER_PORT||8788);
const SECRET=String(process.env.TUBETV_WORKER_SECRET||'');
const FFMPEG=process.env.TUBETV_WORKER_FFMPEG||'ffmpeg';
const MAX_JOBS=Math.max(1,Math.min(3,Number(process.env.TUBETV_WORKER_MAX_JOBS||2)));
const ROOT=path.join(__dirname,'private','adaptive-hls');
const startedAt=Date.now();
const streams=new Map();
let activeJobs=0,totalTranscodes=0,totalCompleted=0,totalFailed=0,totalReused=0,totalBytes=0,lastFailure='';
const byteSamples=[];
let gpuCache={time:0,utilization:null,memoryUsedMb:null,memoryTotalMb:null,temperature:null,encoderUtilization:null};

fs.mkdirSync(ROOT,{recursive:true});
for(const entry of fs.readdirSync(ROOT,{withFileTypes:true}))if(entry.isDirectory())try{fs.rmSync(path.join(ROOT,entry.name),{recursive:true,force:true})}catch(_){}
function json(res,status,data){res.writeHead(status,{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff'});res.end(JSON.stringify(data))}
function readBody(req){return new Promise((resolve,reject)=>{let data='';req.on('data',chunk=>{data+=chunk;if(data.length>65536){reject(new Error('too large'));req.destroy()}});req.on('end',()=>resolve(data));req.on('error',reject)})}
function safeEqual(a,b){const aa=Buffer.from(String(a)),bb=Buffer.from(String(b));return aa.length===bb.length&&crypto.timingSafeEqual(aa,bb)}
function authorized(req,body=''){const stamp=String(req.headers['x-tubetv-timestamp']||''),signature=String(req.headers['x-tubetv-signature']||'');if(!SECRET||!/^\d{13}$/.test(stamp)||Math.abs(Date.now()-Number(stamp))>30000)return false;const signed=req.method==='GET'?stamp+'\n'+req.url:stamp+'\n'+body;return safeEqual(signature,crypto.createHmac('sha256',SECRET).update(signed).digest('hex'))}
function isPrivate(address){if(net.isIP(address)===4){const p=address.split('.').map(Number);return p[0]===10||p[0]===127||p[0]===0||(p[0]===169&&p[1]===254)||(p[0]===172&&p[1]>=16&&p[1]<=31)||(p[0]===192&&p[1]===168)}if(net.isIP(address)===6)return address==='::1'||address.startsWith('fc')||address.startsWith('fd')||address.startsWith('fe80:');return true}
async function allowedSource(value){let url;try{url=new URL(value)}catch(_){return false}if(!['http:','https:'].includes(url.protocol)||url.username||url.password||!url.hostname)return false;const records=await dns.lookup(url.hostname,{all:true});return records.length>0&&records.every(record=>!isPrivate(record.address))}
function ffmpegInput(url){return['-nostdin','-hide_banner','-loglevel','warning','-user_agent','Mozilla/5.0 TubeTV-Assist/2.0','-rw_timeout','20000000','-reconnect','1','-reconnect_streamed','1','-reconnect_at_eof','1','-reconnect_delay_max','5','-i',url]}
function mp4Args(url,quality){const height=quality==='sd'?480:quality==='fullhd'?1080:720,bitrate=quality==='sd'?'1400k':quality==='fullhd'?'5800k':'3000k',width=height===480?854:height===720?1280:1920;return[...ffmpegInput(url),'-map','0:v:0','-map','0:a:0?','-sn','-dn','-vf',`scale=w='trunc(min(${width},iw)/2)*2':h=-2`,'-c:v','h264_nvenc','-preset','p2','-tune','ll','-rc','vbr','-cq','24','-b:v',bitrate,'-maxrate',bitrate,'-bufsize',bitrate,'-g','48','-pix_fmt','yuv420p','-profile:v','main','-c:a','aac','-ac','2','-b:a','128k','-max_muxing_queue_size','2048','-movflags','frag_keyframe+empty_moov+default_base_moof','-f','mp4','pipe:1']}
function hlsArgs(url,dir){for(const name of['sd','hd','fullhd'])fs.mkdirSync(path.join(dir,name),{recursive:true});const segmentPattern=path.join(dir,'%v','segment_%09d.ts').replace(/\\/g,'/'),playlistPattern=path.join(dir,'%v','index.m3u8').replace(/\\/g,'/');return[...ffmpegInput(url),'-filter_complex',"[0:v]split=3[v0][v1][v2];[v0]scale=w='trunc(min(854,iw)/2)*2':h=-2[v0o];[v1]scale=w='trunc(min(1280,iw)/2)*2':h=-2[v1o];[v2]scale=w='trunc(min(1920,iw)/2)*2':h=-2[v2o]",'-map','[v0o]','-map','0:a:0','-map','[v1o]','-map','0:a:0','-map','[v2o]','-map','0:a:0','-c:v','h264_nvenc','-preset','p2','-tune','ll','-rc','vbr','-cq','24','-pix_fmt','yuv420p','-profile:v','main','-g','48','-keyint_min','48','-sc_threshold','0','-b:v:0','1400k','-maxrate:v:0','1700k','-bufsize:v:0','2800k','-b:v:1','3000k','-maxrate:v:1','3500k','-bufsize:v:1','6000k','-b:v:2','5800k','-maxrate:v:2','6500k','-bufsize:v:2','11600k','-c:a','aac','-ac','2','-b:a','128k','-ar','48000','-f','hls','-hls_time','4','-hls_list_size','15','-hls_delete_threshold','8','-hls_flags','delete_segments+independent_segments+program_date_time+temp_file','-master_pl_name','master.m3u8','-var_stream_map','v:0,a:0,name:sd v:1,a:1,name:hd v:2,a:2,name:fullhd','-hls_segment_filename',segmentPattern,playlistPattern]}
function removeDir(dir){try{fs.rmSync(dir,{recursive:true,force:true})}catch(_){}}
function stopStream(job,failed=false){if(!job||job.stopped)return;job.stopped=true;if(job.process&&!job.process.killed)job.process.kill();streams.delete(job.id);activeJobs=Math.max(0,activeJobs-1);totalCompleted++;if(failed){totalFailed++;lastFailure=String(job.stderr||'').replace(/https?:\/\/\S+/gi,'[SOURCE]').slice(-1200);if(lastFailure)process.stderr.write('Adaptive HLS failed: '+lastFailure+'\n')}removeDir(job.dir)}
function pruneViewers(job){for(const[id,time]of job.viewers)if(time<Date.now()-45000)job.viewers.delete(id)}
function touchViewer(job,viewer){if(viewer){job.lastAccess=Date.now();job.viewers.set(String(viewer).slice(0,64),Date.now())}pruneViewers(job)}
function outputReady(job){try{return fs.existsSync(path.join(job.dir,'master.m3u8'))&&['sd','hd','fullhd'].every(name=>fs.readFileSync(path.join(job.dir,name,'index.m3u8'),'utf8').includes('#EXTINF:'))}catch(_){return false}}
function clearLivePlaylists(job){for(const file of['master.m3u8','sd/index.m3u8','hd/index.m3u8','fullhd/index.m3u8'])try{fs.unlinkSync(path.join(job.dir,...file.split('/')))}catch(_){}}
function waitReady(job,timeout=45000){return new Promise(resolve=>{const until=Date.now()+timeout;const check=()=>{if(outputReady(job)){job.ready=true;return resolve(true)}if(job.failed||job.stopped||Date.now()>until)return resolve(false);setTimeout(check,250)};check()})}
function launchAdaptive(job){
  if(!job||job.stopped)return;
  job.generation=(job.generation||0)+1;const generation=job.generation;
  const child=spawn(FFMPEG,hlsArgs(job.url,job.dir),{windowsHide:true,stdio:['ignore','ignore','pipe']});job.process=child;
  child.stderr.on('data',chunk=>{const value=chunk.toString();job.stderr=(job.stderr+value).slice(-12000);if(!job.ready&&outputReady(job))job.ready=true});
  child.on('error',()=>{if(job.generation===generation)job.process=null});
  child.on('close',code=>{
    if(job.stopped||job.generation!==generation)return;job.process=null;job.exitCode=code;
    // Some IPTV origins close an otherwise valid HLS input with 509. Keep the
    // shared output job alive and reconnect one upstream instead of forcing
    // every viewer back to a separate direct provider connection.
    if(Date.now()-job.lastAccess<90000&&job.restarts<20){job.ready=false;clearLivePlaylists(job);job.restarts++;lastFailure='ADAPTIVE_SOURCE_RECONNECTING';setTimeout(()=>launchAdaptive(job),Math.min(12000,2500+job.restarts*750));return}
    job.failed=true;stopStream(job,true);
  });
}
async function startAdaptive(url,viewer){
  const id=crypto.createHmac('sha256',SECRET).update(url).digest('hex').slice(0,32);
  // A device changing channel must not leave its old NVENC input alive. Keep
  // shared jobs only while at least one other viewer is still using them.
  if(viewer)for(const oldJob of [...streams.values()])if(oldJob.id!==id&&oldJob.viewers.has(viewer)){oldJob.viewers.delete(viewer);if(oldJob.viewers.size===0)stopStream(oldJob)}
  let job=streams.get(id);if(job&&!job.failed&&!job.stopped){totalReused++;touchViewer(job,viewer);return job}
  if(activeJobs>=MAX_JOBS)return null;const dir=path.join(ROOT,id);removeDir(dir);fs.mkdirSync(dir,{recursive:true});
  job={id,url,dir,createdAt:Date.now(),lastAccess:Date.now(),viewers:new Map(),ready:false,failed:false,stopped:false,stderr:'',restarts:0,generation:0};touchViewer(job,viewer);streams.set(id,job);activeJobs++;totalTranscodes++;launchAdaptive(job);return job
}
function recordBytes(size){totalBytes+=size;const now=Date.now();byteSamples.push([now,size]);while(byteSamples.length&&byteSamples[0][0]<now-60000)byteSamples.shift()}
function queryGpu(callback){if(Date.now()-gpuCache.time<5000)return callback(gpuCache);execFile('nvidia-smi',['--query-gpu=utilization.gpu,utilization.encoder,memory.used,memory.total,temperature.gpu','--format=csv,noheader,nounits'],{windowsHide:true,timeout:1800},(error,stdout)=>{if(!error){const p=String(stdout).trim().split(',').map(v=>Number(v.trim()));gpuCache={time:Date.now(),utilization:Number.isFinite(p[0])?p[0]:null,encoderUtilization:Number.isFinite(p[1])?p[1]:null,memoryUsedMb:Number.isFinite(p[2])?p[2]:null,memoryTotalMb:Number.isFinite(p[3])?p[3]:null,temperature:Number.isFinite(p[4])?p[4]:null}}else gpuCache={...gpuCache,time:Date.now()};callback(gpuCache)})}
function health(res){queryGpu(gpu=>{let viewers=0,saved=0;const profiles={sd:0,hd:0,fullhd:0};const now=Date.now();for(const job of streams.values()){pruneViewers(job);viewers+=job.viewers.size;saved+=Math.max(0,job.viewers.size-1);if(job.ready){profiles.sd++;profiles.hd++;profiles.fullhd++}}const recentBytes=byteSamples.reduce((sum,item)=>sum+item[1],0);json(res,200,{ok:true,worker:'TubeTV Desktop Assist',mode:'adaptive-hls',activeJobs,maxJobs:MAX_JOBS,activeStreams:streams.size,viewers,providerConnectionsSaved:saved,profiles,outputMbps:Math.round(recentBytes*8/60000/1000*100)/100,totalBytes,totalTranscodes,totalCompleted,totalFailed,totalReused,lastFailure:lastFailure?'AVAILABLE':'',uptimeSeconds:Math.floor((now-startedAt)/1000),gpu:'NVIDIA NVENC',gpuStats:gpu})})}

setInterval(()=>{const now=Date.now();for(const job of [...streams.values()]){pruneViewers(job);if(now-job.lastAccess>90000)stopStream(job)}},10000).unref();

const server=http.createServer(async(req,res)=>{
  const requestUrl=new URL(req.url,'http://worker.local');
  if(req.method==='GET'&&requestUrl.pathname==='/health')return health(res);
  if(req.method==='GET'&&requestUrl.pathname.startsWith('/hls/')){
    if(!authorized(req,''))return json(res,401,{ok:false,error:'WORKER_AUTH_INVALID'});
    const match=requestUrl.pathname.match(/^\/hls\/([a-f0-9]{32})\/(master\.m3u8|(?:sd|hd|fullhd)\/(?:index\.m3u8|segment_\d+\.ts))$/);
    if(!match)return json(res,404,{ok:false,error:'HLS_ASSET_INVALID'});
    const job=streams.get(match[1]);if(!job)return json(res,404,{ok:false,error:'HLS_JOB_EXPIRED'});touchViewer(job,requestUrl.searchParams.get('viewer')||'');
    if(match[2]==='master.m3u8'&&!job.ready)return json(res,404,{ok:false,error:'HLS_ASSET_PENDING'});
    const file=path.join(job.dir,...match[2].split('/'));if(!file.startsWith(job.dir)||!fs.existsSync(file))return json(res,404,{ok:false,error:'HLS_ASSET_PENDING'});
    const stat=fs.statSync(file),playlist=file.endsWith('.m3u8');res.writeHead(200,{'content-type':playlist?'application/vnd.apple.mpegurl':'video/mp2t','content-length':stat.size,'cache-control':playlist?'no-store':'private, max-age=90, immutable','x-tubetv-worker':'desktop-nvenc','x-tubetv-worker-cache':'1','x-content-type-options':'nosniff'});recordBytes(stat.size);fs.createReadStream(file).pipe(res);return;
  }
  if(req.method==='POST'&&requestUrl.pathname==='/hls/stop'){
    let body;try{body=await readBody(req)}catch(_){return json(res,413,{ok:false,error:'BODY_INVALID'})}if(!authorized(req,body))return json(res,401,{ok:false,error:'WORKER_AUTH_INVALID'});let data;try{data=JSON.parse(body)}catch(_){return json(res,422,{ok:false,error:'JSON_INVALID'})}const id=String(data.id||''),viewer=String(data.viewer||'');const job=streams.get(id);if(!job)return json(res,200,{ok:true,stopped:false});if(viewer)job.viewers.delete(viewer);if(!viewer||job.viewers.size===0)stopStream(job);return json(res,200,{ok:true,stopped:job.stopped,viewers:job.viewers.size});
  }
  if(req.method==='POST'&&requestUrl.pathname==='/hls/start'){
    let body;try{body=await readBody(req)}catch(_){return json(res,413,{ok:false,error:'BODY_INVALID'})}if(!authorized(req,body))return json(res,401,{ok:false,error:'WORKER_AUTH_INVALID'});let data;try{data=JSON.parse(body)}catch(_){return json(res,422,{ok:false,error:'JSON_INVALID'})}const url=String(data.url||''),viewer=String(data.viewer||'');try{if(!(await allowedSource(url)))return json(res,422,{ok:false,error:'SOURCE_NOT_ALLOWED'})}catch(_){return json(res,422,{ok:false,error:'SOURCE_DNS_FAILED'})}const job=await startAdaptive(url,viewer);if(!job)return json(res,429,{ok:false,error:'WORKER_BUSY'});const ready=await waitReady(job,5000);return json(res,ready?200:202,{ok:true,id:job.id,ready,profiles:ready?['sd','hd','fullhd']:[],reused:job.createdAt<Date.now()-1000});
  }
  if(req.method!=='POST'||requestUrl.pathname!=='/transcode')return json(res,404,{ok:false,error:'NOT_FOUND'});
  let body;try{body=await readBody(req)}catch(_){return json(res,413,{ok:false,error:'BODY_INVALID'})}if(!authorized(req,body))return json(res,401,{ok:false,error:'WORKER_AUTH_INVALID'});if(activeJobs>=MAX_JOBS)return json(res,429,{ok:false,error:'WORKER_BUSY'});let data;try{data=JSON.parse(body)}catch(_){return json(res,422,{ok:false,error:'JSON_INVALID'})}const url=String(data.url||''),quality=['sd','hd','fullhd'].includes(data.quality)?data.quality:'hd';try{if(!(await allowedSource(url)))return json(res,422,{ok:false,error:'SOURCE_NOT_ALLOWED'})}catch(_){return json(res,422,{ok:false,error:'SOURCE_DNS_FAILED'})}activeJobs++;totalTranscodes++;const process=spawn(FFMPEG,mp4Args(url,quality),{windowsHide:true,stdio:['ignore','pipe','pipe']});let answered=false,stderr='',startupTimer;const finish=()=>{activeJobs=Math.max(0,activeJobs-1);totalCompleted++;clearTimeout(startupTimer)};process.stderr.on('data',chunk=>{if(stderr.length<8192)stderr+=chunk.toString()});process.stdout.once('data',chunk=>{answered=true;res.writeHead(200,{'content-type':'video/mp4','content-disposition':'inline; filename="tubetv-worker.mp4"','cache-control':'no-store','x-accel-buffering':'no','x-tubetv-worker':'desktop-nvenc','x-content-type-options':'nosniff'});recordBytes(chunk.length);res.write(chunk);process.stdout.on('data',part=>recordBytes(part.length));process.stdout.pipe(res)});startupTimer=setTimeout(()=>{if(!answered)process.kill()},22000);process.on('error',()=>{totalFailed++;if(!answered&&!res.headersSent)json(res,503,{ok:false,error:'WORKER_FFMPEG_UNAVAILABLE'});finish()});process.on('close',code=>{if(!answered&&!res.headersSent){totalFailed++;json(res,502,{ok:false,error:/401|403/.test(stderr)?'SOURCE_DENIED':'WORKER_TRANSCODE_FAILED',code})}else if(!res.writableEnded)res.end();finish()});req.on('close',()=>{if(!res.writableEnded)process.kill()});
});
server.keepAliveTimeout=65000;server.headersTimeout=70000;server.listen(PORT,HOST,()=>process.stdout.write(`TubeTV Desktop Assist on ${HOST}:${PORT}\n`));
