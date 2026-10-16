import {chromium} from 'playwright';
import {readFileSync, existsSync, statSync, mkdirSync} from 'node:fs';
import {resolve, sep} from 'node:path';
import assert from 'node:assert/strict';
const browser=await chromium.launch({channel:'chrome',headless:true});
const context=await browser.newContext({serviceWorkers:'block',reducedMotion:'reduce'});
const page=await context.newPage();
const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.addInitScript(()=>Object.defineProperty(window,'speechSynthesis',{value:{cancel(){},speak(u){window.lastSpoken=u.text;}}}));
const alpine=readFileSync('node_modules/alpinejs/dist/cdn.min.js','utf8');
let retries=0;
await page.route('**/*',async route=>{
 const url=new URL(route.request().url());
 if(url.pathname.endsWith('/retry-media')){retries++;return route.fulfill({contentType:'text/html',body:'Retry received'});}
 if(url.pathname.startsWith('/fixture/')){
  const html=readFileSync(`storage/tmp-assessment-browser/${url.pathname.split('/').at(-1)}.html`,'utf8').replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace('</body>',()=>`<script>${alpine}</script></body>`);
  return route.fulfill({contentType:'text/html',body:html});
 }
 if(url.pathname.endsWith('/preview/original.png'))return route.fulfill({contentType:'image/png',body:readFileSync('tests/Fixtures/evidence/media/notice.png')});
 const file=resolve('public','.'+url.pathname);
 if(file.startsWith(resolve('public')+sep)&&existsSync(file)&&statSync(file).isFile())return route.fulfill({contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'application/javascript':'image/png',body:readFileSync(file)});
 return route.fulfill({status:204});
});
mkdirSync('storage/tmp-assessment-browser/screenshots',{recursive:true});
try{
 for(const variant of ['factual','media','calibrated','unverified','long','missing','legacy','websearch','context','satire']){
  for(const width of [1920,1366,768,390,320]){
   await page.setViewportSize({width,height:width>=1366?900:844});
   await page.goto(`http://127.0.0.1:8000/fixture/${variant}`);
   await page.waitForFunction(()=>document.querySelector('.tg-result-report')._x_dataStack);
   await page.evaluate(()=>document.documentElement.classList.remove('tg-loading'));
   const check=async()=>assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&document.querySelector('.tg-result-report').scrollWidth<=document.querySelector('.tg-result-report').clientWidth+1),`${variant} ${width}: overflow`);
   await check();
   if(['context','satire'].includes(variant)&&[1366,390].includes(width))await page.screenshot({path:`storage/tmp-assessment-browser/screenshots/${variant}-${width}.png`,fullPage:true});
   if(['context','satire'].includes(variant)) {
    assert.equal(await page.locator('.tg-result-assessment > .tg-result-verdict-heading .tg-result-eyebrow').innerText(),'CONTEXT ASSESSMENT');
    assert.ok(await page.getByText('Fact-check verdict',{exact:true}).isVisible());
    assert.equal(await page.getByText('AI-generation assessment',{exact:true}).count(),0);
    for(const target of await page.locator('a.tg-result-citation').evaluateAll(links=>links.map(link=>link.getAttribute('href'))))assert.equal(await page.locator(target).count(),1);
   }
   assert.equal(await page.locator('#assessment-details').getAttribute('open'),null);
   assert.ok(await page.locator('#sources-to-verify').isVisible());
   assert.equal(await page.locator('#sources-to-verify').evaluate(el=>el.closest('details')),null);
   assert.ok(await page.locator('#assessment-details').evaluate(el=>el.parentElement.classList.contains('tg-result-report')&&el.nextElementSibling.classList.contains('tg-result-verify-sources')));
   assert.ok(await page.locator('#assessment-details').evaluate(el=>{
    const verdict=document.querySelector('.tg-result-assessment').getBoundingClientRect();
    const details=el.getBoundingClientRect();
    const sources=el.nextElementSibling.getBoundingClientRect();
    return verdict.bottom<=details.top+1&&details.bottom<=sources.top+1;
   }),`${variant} ${width}: details sits between verdict and sources`);
   assert.ok(await page.locator('.tg-result-evidence').evaluate(el=>Boolean(el.compareDocumentPosition(document.querySelector('.tg-result-assessment'))&Node.DOCUMENT_POSITION_FOLLOWING)));
   if(width>=1200)assert.ok(await page.locator('.tg-result-evidence').evaluate(el=>{
    const evidence=el.getBoundingClientRect();
    const verdict=document.querySelector('.tg-result-assessment').getBoundingClientRect();
    return Math.abs(evidence.top-verdict.top)<=1&&Math.abs(evidence.bottom-verdict.bottom)<=1;
   }),`${variant} ${width}: evidence and verdict cards align`);
   if([1920,390].includes(width)){
    const help=page.locator('.tg-result-verdict-help button');
    const tooltip=page.locator('.tg-result-verdict-tooltip');
    assert.ok((await help.getAttribute('aria-label')).includes((await page.locator('#fact-check-verdict').innerText())+': '));
    if(variant==='unverified')assert.match(await tooltip.textContent(),/does not mean the claim is false/i);
    if(variant==='media')assert.match(await tooltip.textContent(),/does not prove the media is authentic/i);
    await help.hover();assert.ok(await tooltip.isVisible());await check();
    if(variant==='unverified')await page.screenshot({path:`storage/tmp-assessment-browser/screenshots/tooltip-unverified-${width}.png`});
    await page.mouse.move(0,0);assert.ok(!(await tooltip.isVisible()));
    await help.focus();assert.ok(await tooltip.isVisible());await check();
    await help.evaluate(el=>el.blur());
   }
   if(['media','unverified','legacy'].includes(variant)) assert.ok(!(await page.locator('.tg-result-score').innerText()).includes('Confidence:'));
   if(variant==='calibrated'){
    assert.match(await page.locator('.tg-result-score').innerText(),/Confidence: 75%/);
    assert.match(await page.locator('.tg-result-score').innerText(),/Calibrated on validation data/);
   }
   if(variant==='factual'){
    if(width===1920){for(const destination of ['dashboard','fact-check','history','notifications'])assert.ok(await page.locator(`a[data-tour="${destination}"]`).isVisible(),`${destination} navigation is visible`);}
    if(width===390)assert.ok(await page.getByRole('link',{name:'Go back',exact:true}).isVisible());
    assert.equal(await page.locator('.tg-result-source-cards > article:visible').count(),3);
    if([1920,390].includes(width))await page.locator('.tg-result-verify-sources').screenshot({path:`storage/tmp-assessment-browser/screenshots/sources-${width}.png`});
    const citationTargets=await page.locator('a.tg-result-citation').evaluateAll(links=>links.map(link=>link.getAttribute('href')));
    assert.ok(citationTargets.length>0);
    for(const target of citationTargets)assert.equal(await page.locator(target).count(),1,`citation ${target} resolves to a saved source`);
    await page.locator('a.tg-result-citation').first().click();
    assert.ok(await page.locator(citationTargets[0]).isVisible());
    await page.locator('.tg-result-more-sources > summary').click();
    assert.ok(await page.locator('#evidence-source-4').isVisible());
    assert.equal(await page.locator('#assessment-details').getAttribute('open'),null);
    assert.equal(await page.locator('#evidence-source-1 h3').innerText(),'Reviewed source 1 — synthetic UI fixture');
    assert.match(await page.locator('#evidence-source-1').innerText(),/Fixture publisher 1[\s\S]*Published \/ issued: Oct 13, 2026[\s\S]*contradicts the demonstration statement/);
    const urls=await page.locator('.tg-result-verify-sources a[target="_blank"]').evaluateAll(links=>links.map(l=>[l.href,l.rel,l.textContent]));
    assert.equal(urls.length,4);assert.ok(urls.every(([url,rel,text])=>url.startsWith('https://example.com/evidence-')&&rel.includes('noopener')&&text.includes('Open original source')));
   }
   if(variant==='long'){
    assert.ok((await page.locator('#evidence-source-1 h3').innerText()).includes('LongSourceTitle'));
    assert.ok((await page.locator('#evidence-source-1 .tg-result-source-purpose').innerText()).includes('LongSourceDetail'));
    await check();
   }
    await page.locator('#assessment-details > summary').click();await check();
    assert.equal(await page.locator('.tg-result-diagnostics, .tg-result-audit-json').count(),0);
    assert.equal(await page.getByText('Original extraction record',{exact:true}).count(),0);
    assert.equal(await page.getByText('Discovery and retrieval records',{exact:true}).count(),0);
   assert.ok(await page.locator('#sources-to-verify').isVisible());
   await page.locator('#assessment-details > summary').click();
   if(variant==='factual'&&[1920,390].includes(width)){
    await page.evaluate(()=>document.querySelectorAll('*').forEach(e=>{if(e.scrollTop)e.scrollTop=0;}));
    await page.screenshot({path:`storage/tmp-assessment-browser/screenshots/${width}.png`,fullPage:true});
   }
  }
  console.log(`PASS ${variant}: desktop/tablet/mobile, source visibility, score labeling, overflow`);
 }
 await page.setViewportSize({width:1366,height:900});
 await page.goto('http://127.0.0.1:8000/fixture/websearch');
  await page.locator('#assessment-details > summary').click();
  const publicReport=await page.locator('.tg-result-report').innerText();
  for(const sentinel of ['DEV_QUERY_SENTINEL','DEV_OCR_SENTINEL','DEV_PROVIDER_SENTINEL','123 API tokens'])assert.ok(!publicReport.includes(sentinel),`internal ${sentinel} leaked into report`);
  await page.getByRole('button',{name:'Read result aloud',exact:true}).click();
  const spoken=await page.evaluate(()=>window.lastSpoken);
  assert.ok(!['DEV_QUERY_SENTINEL','DEV_OCR_SENTINEL','DEV_PROVIDER_SENTINEL'].some(s=>spoken?.includes(s)));
 await page.goto('http://127.0.0.1:8000/fixture/media');
 assert.equal(await page.getByText('View full image',{exact:true}).count(),0);
 const previewSize=await page.locator('.tg-result-image-trigger img').boundingBox();
 await page.locator('.tg-result-image-trigger img').click();assert.equal(await page.locator('dialog[open]').count(),1);
 const enlargedSize=await page.locator('.tg-result-lightbox img').boundingBox();
 assert.ok(enlargedSize.width>previewSize.width&&enlargedSize.height>previewSize.height,'image grows in dialog');
 await page.keyboard.press('Escape');
 await page.getByRole('button',{name:'Enlarge submitted image'}).focus();await page.keyboard.press('Enter');assert.equal(await page.locator('dialog[open]').count(),1);await page.getByRole('button',{name:'Close image preview'}).click();
 await page.getByRole('button',{name:'Read result aloud',exact:true}).click();assert.ok((await page.evaluate(()=>window.lastSpoken)).includes('does not prove'));
 await page.evaluate(()=>document.documentElement.requestFullscreen());assert.ok(await page.evaluate(()=>document.fullscreenElement&&document.documentElement.scrollWidth<=innerWidth+1));await page.evaluate(()=>document.exitFullscreen());
 await page.locator('#assessment-details > summary').click();await page.getByRole('button',{name:'Retry media analysis',exact:true}).click();await page.getByText('Retry received').waitFor();assert.equal(retries,1);
 for(const width of [1366,390]){
  await page.setViewportSize({width,height:900});
  await page.goto('http://127.0.0.1:8000/fixture/pending');
  await page.evaluate(()=>document.documentElement.classList.remove('tg-loading'));
  await page.getByText('Checking dates and official records',{exact:true}).waitFor();
  assert.ok(await page.getByText('www.comelec.gov.ph',{exact:true}).isVisible());
  assert.ok(await page.getByText('verafiles.org',{exact:true}).isVisible());
  assert.equal(await page.locator('[aria-label="Retrieved source websites"] span').count(),2);
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 }
 assert.deepEqual(errors,[]);console.log('PASS zoom, read aloud, fullscreen, retry form and actual-domain progress badges; no JavaScript errors.');
}finally{await browser.close();}
