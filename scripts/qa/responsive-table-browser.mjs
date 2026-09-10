import {createServer} from 'vite';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {readFileSync,readdirSync} from 'node:fs';
import {chromium} from '@playwright/test';
const server=await createServer({server:{middlewareMode:true},appType:'custom'});
let browser;
try {
const {Table,TableHeader,TableBody,TableRow,TableHead,TableCell}=await server.ssrLoadModule('/resources/js/Components/ui/table.tsx');
const el=React.createElement;
const labels=['Nome','Referência','Valor','Estado','Ações'];
const markup=renderToStaticMarkup(el(Table,{responsive:true},el(TableHeader,null,el(TableRow,null,...labels.map(t=>el(TableHead,{key:t},t)))),el(TableBody,null,el(TableRow,null,...labels.map((t,i)=>el(TableCell,{key:t,label:t},i===4?el('button',null,'Abrir registo'):i===1?'X'.repeat(100):'Conteúdo integral do campo '+t))))));
const css=readdirSync('public/build/assets').filter(f=>f.endsWith('.css')).map(f=>readFileSync('public/build/assets/'+f,'utf8')).join('\n');
browser=await chromium.launch();const page=await browser.newPage();
for(const width of [320,375,768,1280,1920]){
 await page.setViewportSize({width,height:900});await page.setContent('<style>'+css+'</style><main style="padding:10px">'+markup+'</main>');
 const result=await page.evaluate(()=>({page:document.documentElement.scrollWidth<=innerWidth,table:[...document.querySelectorAll('.responsive-table,td')].every(e=>e.scrollWidth<=e.clientWidth+1),labels:getComputedStyle(document.querySelector('.responsive-table-label')).display}));
 console.log(width,result);if(!result.page||!result.table)throw Error('Overflow at '+width);
 await page.getByRole('button',{name:'Abrir registo'}).click();
}
} finally {
 await browser?.close();
 await server.close();
}
