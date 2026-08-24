const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync('index.html', 'utf8');

function check(condition, message) {
  if (!condition) throw new Error(message);
}

check(html.includes('const displayItems=queue.slice(0,3);'), 'the live sidebar is not limited to three items');
check(html.includes('function openTodayProgrammeGuide()'), 'the complete daily guide is missing');
check(html.includes('getSchedulePublication(item)'), 'publication dates are missing from the daily guide');
check(html.includes("rewind24:{name:'Rewind 24h'") && html.includes("rewind3:{name:'Rewind 3'") && html.includes("rewind7:{name:'Rewind 7'") && html.includes("rewind30:{name:'Rewind 30'"), 'desktop Rewind channels are missing');
check(html.includes('function skipRewindVideo()') && html.includes("'&anchor='") && html.includes("'&advance='"), 'personal Rewind skip is not connected to live-state');
check(html.includes('id="live-channel-bug"') && html.includes('live-web-1.svg?v=20260824-v4'), 'the refreshed Live Web 1 channel logo is missing');
check(html.includes('id="live-program-badge-text"') && html.includes('function renderLiveEditorialOverlay(current)'), 'the V4 editorial live badge is missing');
check(html.includes('PROSSIMA PREMIERE') && html.includes('nextRatedPremiere'), 'the next high-rated Premiere announcement is missing');

check(html.includes('function getLiveNowMs(){'), 'the server-aligned clock helper is missing');
check(!html.includes('endAt - liveNowMs()'), 'the live boundary still calls the missing liveNowMs helper');
check(html.includes('endAt - getLiveNowMs()'), 'the exact live boundary is not using the server-aligned clock');
check(html.includes("cache locale piena, continuo online"), 'localStorage quota failures can still abort live startup');
check(!html.includes("videoLibrary:'video_library'"), 'the full video library is still being copied into localStorage');
check(html.includes('function disposeLivePreloader(){'), 'the obsolete hidden YouTube preloader is not cleaned up');
const start = html.indexOf('function getOfficialLiveOffset(item, liveState)');
const end = html.indexOf('function getLiveStatePhase(state){', start);
check(start >= 0 && end > start, 'could not extract the live offset implementation');

let clock = Date.parse('2026-08-18T10:02:10.000Z');
class TestDate extends Date {
  static now() { return clock; }
}

const context = {
  Date: TestDate,
  Number,
  Math,
  DB: {},
};
vm.createContext(context);
vm.runInContext(`function getLiveNowMs(){return Date.now();}${html.slice(start, end)}`, context);

const ordinary = context.getOfficialLiveOffset({}, {
  currentStartedAt: '2026-08-18T10:00:00.000Z',
  currentVideoOffset: 0,
  currentDurationSeconds: 1800,
});
check(ordinary === 130, `ordinary live offset is ${ordinary}, expected 130`);

const resumed = context.getOfficialLiveOffset({}, {
  currentStartedAt: '2026-08-18T09:58:00.000Z',
  currentVideoOffset: 120,
  serverNowAtPublish: '2026-08-18T10:00:00.000Z',
  currentDurationSeconds: 1800,
});
check(resumed === 250, `resumed live offset is ${resumed}, expected 250 without double counting`);

clock = Date.parse('2026-08-18T11:00:00.000Z');
const clamped = context.getOfficialLiveOffset({}, {
  currentStartedAt: '2026-08-18T10:00:00.000Z',
  currentVideoOffset: 0,
  currentDurationSeconds: 1800,
});
check(clamped === 1799, `live offset was not clamped to the video duration: ${clamped}`);

console.log('live-ui-tests PASS');
