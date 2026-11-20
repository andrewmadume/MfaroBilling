const CACHE='mfaro-public-v1';
const PUBLIC=['/offline.html','/icon.svg','/icon-192.png','/icon-512.png'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(c=>c.addAll(PUBLIC)))});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))))});
self.addEventListener('fetch',event=>{
 const request=event.request;const url=new URL(request.url);
 // Never intercept API, authentication, checkout, cross-origin or mutating requests.
 if(request.method!=='GET'||url.origin!==self.location.origin||url.pathname.startsWith('/api/')||url.pathname.startsWith('/auth/'))return;
 if(request.mode==='navigate'){event.respondWith(fetch(request,{cache:'no-store'}).catch(()=>caches.match('/offline.html')));return}
 if(PUBLIC.includes(url.pathname)||/^\/assets\/[\w.-]+\.(js|css|woff2|png|svg)$/.test(url.pathname)){
  event.respondWith(caches.match(request).then(hit=>hit||fetch(request).then(response=>{if(response.ok&&response.type==='basic'){const copy=response.clone();caches.open(CACHE).then(c=>c.put(request,copy))}return response})));
 }
});
