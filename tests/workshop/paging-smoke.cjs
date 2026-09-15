const {chromium}=require('playwright');const assert=require('node:assert/strict');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE});try{
 const page=await browser.newPage({viewport:{width:390,height:844}});
 await page.goto('http://127.0.0.1:3031/?q=fixture-pages');
 assert.equal(await page.locator('.rw-tile').count(),12);
 assert.match(await page.getByRole('link',{name:'Add/view photos'}).first().getAttribute('href'),/part=abc-dead-end-clamp/);
 await page.getByRole('link',{name:'Next 12 AM candidates',exact:true}).first().click();
 await page.waitForURL(/page=2/);assert.equal(await page.locator('.rw-tile').count(),12);
 assert.ok(await page.getByText('Fixture candidate 13',{exact:true}).count());
 await page.locator('[name="asset_ids[]"]').first().check();
 page.once('dialog',d=>d.dismiss());await page.getByRole('link',{name:'Next 12 AM candidates',exact:true}).first().click();assert.match(page.url(),/page=2/);
 page.once('dialog',d=>d.accept());await page.getByRole('link',{name:'Next 12 AM candidates',exact:true}).first().click();
 await page.waitForURL(/page=3/);assert.equal(await page.locator('.rw-tile').count(),1);
 assert.equal(await page.getByRole('link',{name:'Next 12 AM candidates',exact:true}).count(),0);
 assert.ok(await page.getByRole('link',{name:'Next UGP requirement (does not save)',exact:true}).count());
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth));
 console.log('PASS: 25 candidates reachable, filters retained, unsaved warning and separate UGP navigation; no writes');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});
