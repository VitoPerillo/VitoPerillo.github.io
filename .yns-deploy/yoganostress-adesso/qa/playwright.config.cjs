const { defineConfig, devices } = require('@playwright/test');
module.exports=defineConfig({
 testDir:'.', testMatch:'dummy.spec.js', timeout:30000, expect:{timeout:7000}, fullyParallel:false,
 reporter:[['list'],['html',{outputFolder:'playwright-report',open:'never'}]],
 use:{baseURL:'http://127.0.0.1:4173',trace:'retain-on-failure',screenshot:'only-on-failure'},
 projects:[
  {name:'desktop-chromium',use:{...devices['Desktop Chrome']}},
  {name:'mobile-chromium',use:{...devices['Pixel 7']}}
 ]
});