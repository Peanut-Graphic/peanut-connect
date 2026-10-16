const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
 const url=process.env.PEANUT_FORMS_TEST_URL;
 assert.ok(url, 'Set PEANUT_FORMS_TEST_URL to a disposable test page containing the cache-isolation-test form.');
 const origin=new URL(url).origin;
 const cached = await (await fetch(url)).text();
 assert.ok(cached.includes('peanut-form-schema'), 'real shortcode schema missing');
 assert.ok(cached.includes('/assets/js/forms.js'), 'bundled renderer missing');
 assert.ok(!cached.includes('data-visitor-id='));
 assert.ok(!cached.includes('data-session-id='));
 const browser=await chromium.launch({headless:true});
 try {
  const results=[];
  for (const [label,id] of [['A','a'.repeat(32)], ['B','b'.repeat(32)]]) {
   const context = await browser.newContext();
   await context.addCookies([{name:'peanut_vid',value:id,url:origin}]);
   const page=await context.newPage();
   const errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route(url,route=>route.fulfill({status:200,contentType:'text/html',body:cached}));
   await page.goto(url);
   await page.getByLabel('Name',{exact:true}).fill('Visitor '+label);
   await page.getByLabel('Email',{exact:true}).fill('visitor-'+label.toLowerCase()+'@example.invalid');
   const submitted=page.waitForResponse(r=>r.url().includes('/forms/submit'));
   await page.getByRole('button',{name:'Send',exact:true}).click();
   const response=await submitted;
   const body=await response.json();
   assert.equal(response.status(),200,JSON.stringify(body));
   assert.equal(body.success,true);
   await page.getByRole('status').filter({hasText:'Validation submission received.'}).waitFor();
   const payload=response.request().postDataJSON();
   assert.ok(!('visitor_id' in payload));
   results.push({label,uuid:body.submission_uuid,session:payload.session_id,cookie:id,errors});
   assert.equal(errors.length,0,errors.join('\n'));
   await page.screenshot({path:(process.env.PEANUT_FORMS_EVIDENCE_DIR || '/tmp')+'/peanut-forms-visitor-'+label+'.png',fullPage:true});
   await context.close();
  }
  assert.notEqual(results[0].session,results[1].session);
  fs.writeFileSync((process.env.PEANUT_FORMS_EVIDENCE_DIR || '/tmp')+'/peanut-forms-browser-results.json',JSON.stringify(results,null,2));
  console.log(JSON.stringify({cacheReplay:true,results},null,2));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
