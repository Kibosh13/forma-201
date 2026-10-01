document.addEventListener('DOMContentLoaded',()=>{const b=document.querySelector('.menu-toggle');const n=document.querySelector('.primary-nav');if(b&&n)b.addEventListener('click',()=>{n.classList.toggle('open');b.setAttribute('aria-expanded',n.classList.contains('open')?'true':'false')})});

