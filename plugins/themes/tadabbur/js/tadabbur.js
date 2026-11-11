/*! Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later */
/* Tadabbur theme: copy DOI + header shadow on scroll (no dependencies) */
(function () {
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.tdb_copy');
		if (!b) return;
		var t = b.getAttribute('data-copy'), done = b.getAttribute('data-done') || 'OK', old = b.textContent;
		var ok = function () { b.textContent = done; b.classList.add('is-done'); setTimeout(function () { b.textContent = old; b.classList.remove('is-done'); }, 1800); };
		if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(ok); }
		else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); ok(); } catch (x) {} document.body.removeChild(a); }
	});
	var bt = document.querySelector('.tdb-burger'), nv = document.getElementById('tdbNav');
	if (bt && nv) bt.addEventListener('click', function () { setTimeout(function () { bt.setAttribute('aria-expanded', nv.classList.contains('pkp_site_nav_menu--isOpen') ? 'true' : 'false'); }, 0); });
	var h = document.querySelector('.tdb-nav');
	if (h) { var f = function () { h.classList.toggle('is-scrolled', window.scrollY > 8); }; f(); window.addEventListener('scroll', f, { passive: true }); }
})();
