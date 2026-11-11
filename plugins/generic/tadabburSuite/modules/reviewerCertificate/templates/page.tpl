{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{**
 * plugins/generic/reviewerCertificate/templates/page.tpl
 * صفحة داخل لوحة OJS؛ المحتوى HTML بلا سكربتات (تطبيق Vue يتجاهلها).
 *}
{extends file="layouts/backend.tpl"}
{block name="page"}
	<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
	<div class="app__contentPanel" dir="rtl" style="font-size:14px;line-height:1.7">
		{$rcHtml|tdbui nofilter}
	</div>
	<div style="margin-top:18px;padding-top:10px;border-top:1px solid #e1e4e8;font-size:12px;color:#6b7280;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center">
		<span>© 2026 <a href="https://khibrattaibah.com" target="_blank" rel="noopener">مكتب خبرات طيبة للبحوث والدراسات</a> · تطوير: <b>مصطفى محمود</b> (Moustafa Mahmoud)</span>
		<a href="https://github.com/mostafamr2011" target="_blank" rel="noopener">GitHub</a>
		<a href="https://orcid.org/0009-0008-0991-3443" target="_blank" rel="noopener">ORCID</a>
		<a href="https://www.researchgate.net/profile/Moustafa-Mahmoud-25" target="_blank" rel="noopener">ResearchGate</a>
		<a href="https://scholar.google.com/citations?user=ghQmYo8AAAAJ" target="_blank" rel="noopener">Google Scholar</a>
		<span>GPL-3.0</span>
	</div>
{/block}
