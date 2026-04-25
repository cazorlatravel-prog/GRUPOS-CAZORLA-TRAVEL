const CACHE='cazorla-v7';
const ASSETS=['./','./index.html','./manifest.json','./icons/icon-192.png','./icons/icon-512.png'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS).catch(()=>{})).then(()=>self.skipWaiting()))});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',e=>{
  const url=e.request.url;
  if(url.includes('api.php')){e.respondWith(fetch(e.request));return}
  e.respondWith(fetch(e.request).then(r=>{
    if(r&&r.ok&&e.request.method==='GET'){const cl=r.clone();caches.open(CACHE).then(c=>c.put(e.request,cl)).catch(()=>{})}
    return r;
  }).catch(()=>caches.match(e.request)));
});
