// Offline dark-mode contrast checks against rendered workspace fixtures; no live services.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import {readFileSync,existsSync,statSync,writeFileSync} from 'node:fs';
import {resolve,sep} from 'node:path';
const browser=await chromium.launch({channel:'chrome',headless:true});
const root=resolve('public');
const css=JSON.parse(readFileSync('public/build/manifest.json','utf8'))['resources/css/app.css'].file;
const alpine=readFileSync('node_modules/alpinejs/dist/cdn.min.js','utf8');
const pages=(process.env.DARK_PAGES||'dashboard,create,result,history,inbox,notice,article,settings-personal,settings-appearance,settings-photo,settings-security,settings-notifications,settings-account').split(',');
const output=[];
try{for(const name of pages){
const raw=readFileSync(`storage/tmp-dark-mode-${name}.html`,'utf8');
const html=raw.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,s=>s.includes('const allowedModes')?s:'').replace(/assets\/app-[\w-]+\.css/g,css);
const page=await browser.newPage({viewport:{width:Number(process.env.DARK_WIDTH||1440),height:1000},serviceWorkers:'block',reducedMotion:'reduce'});
const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.addInitScript(()=>{localStorage.setItem('truthguard-display-mode','dark');});
await page.route('**/*',route=>{const u=new URL(route.request().url());if(route.request().isNavigationRequest())return route.fulfill({contentType:'text/html',body:html});const file=resolve(root,'.'+u.pathname);if(u.origin==='http://127.0.0.1:8000'&&file.startsWith(root+sep)&&existsSync(file)&&statSync(file).isFile())return route.fulfill({contentType:file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'image/png',body:readFileSync(file)});return route.fulfill({status:204});});
const path=name.startsWith('settings-')?'/profile?section='+name.slice(9):({dashboard:'/dashboard',create:'/detections/create',result:'/detections/1/result',history:'/history',inbox:'/notifications',notice:'/notifications/fixture',article:'/dashboard/fact-checks/sample-review'})[name];
await page.goto('http://127.0.0.1:8000'+path);
await page.evaluate(()=>document.querySelector('#truthguard-app-splash')?.remove());
await page.addScriptTag({content:alpine});
await page.addStyleTag({content:'*,*::before,*::after{transition-duration:0s!important;animation-duration:0s!important;}'});
const permissionsLater=page.getByRole('button',{name:'Do this later',exact:true});
if(await permissionsLater.isVisible())await permissionsLater.click();
await page.evaluate(()=>{const bell=document.querySelector('[x-ref="notificationBell"]');if(bell)Alpine.$data(bell.closest('[x-data]')).toast=null;});
await page.waitForTimeout(200);
const failures=await page.evaluate(()=>{
 const rgba=s=>{const space=s?.startsWith('color(srgb');const n=s?.match(/[\d.]+/g)?.map(Number);return n?.length>=3?[...n.slice(0,3).map(v=>space?v*255:v),n[3]??1]:[0,0,0,0];};
 const over=(a,b)=>{const alpha=a[3]+b[3]*(1-a[3]);return [...a.slice(0,3).map((n,i)=>(n*a[3]+b[i]*b[3]*(1-a[3]))/(alpha||1)),alpha];};
 const lum=a=>a.slice(0,3).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
 const bg=el=>{let color=[0,0,0,0];for(let e=el;e;e=e.parentElement){const style=getComputedStyle(e);let layer=rgba(style.backgroundColor);if(style.backgroundImage.includes('gradient')){const match=style.backgroundImage.match(/rgba?\([^)]*\)/);if(match)layer=over(rgba(match[0]),layer);}color=over(color,layer);if(color[3]>.99)break;}return over(color,[15,23,42,1]);};
 const bad=[];const seen=new Set();
 for(const el of document.body.querySelectorAll('*')){if(['SCRIPT','STYLE','SVG','PATH','OPTION'].includes(el.tagName)||el.closest('[aria-hidden="true"], [inert]'))continue;const text=[...el.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent.trim()).join(' ').trim();if(!text||!el.getClientRects().length)continue;const style=getComputedStyle(el);if(style.visibility!=='visible'||parseFloat(style.opacity)<.5||style.display==='none')continue;const box=el.getBoundingClientRect();if(box.width<2||box.height<2)continue;const background=bg(el);const fg=over(rgba(style.color),background);const ratio=(Math.max(lum(fg),lum(background))+.05)/(Math.min(lum(fg),lum(background))+.05);const large=parseFloat(style.fontSize)>=24||(parseFloat(style.fontSize)>=18.66&&Number(style.fontWeight)>=700);if(ratio<(large?3:4.5)){const item={text:text.slice(0,90),tag:el.tagName,cls:el.className,ratio:Math.round(ratio*100)/100,color:style.color,bg:background.slice(0,3).map(Math.round),size:style.fontSize,path:[el.parentElement,el.parentElement?.parentElement,el.parentElement?.parentElement?.parentElement].filter(Boolean).map(e=>e.tagName+'.'+e.className)};const key=item.cls+item.color+item.bg;if(!seen.has(key)){bad.push(item);seen.add(key);}}}
 return bad;
});
await page.screenshot({path:`storage/tmp-dark-${name}-${process.env.DARK_WIDTH||1440}.png`,fullPage:!name.startsWith('settings-')});
output.push({name,failures,errors});await page.close();
}writeFileSync('storage/tmp-dark-mode-audit-'+(process.env.DARK_WIDTH||1440)+'.json',JSON.stringify(output,null,2));for(const page of output){assert.deepEqual(page.errors,[],page.name);assert.deepEqual(page.failures,[],JSON.stringify({page:page.name,failures:page.failures}));}console.log(JSON.stringify(output.map(({name})=>({name,width:Number(process.env.DARK_WIDTH||1440),contrast:true,noScriptErrors:true}))));}finally{await browser.close();}
