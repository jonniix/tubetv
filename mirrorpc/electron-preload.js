'use strict';
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('mirrorPCNative', { captureDesktop: mode => ipcRenderer.invoke('mirrorpc:capture-primary', mode), platform: 'windows' });

