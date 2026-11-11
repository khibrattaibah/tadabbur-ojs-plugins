{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$olTitle}
<div class="page page_letter_verify">
	{include file="frontend/components/breadcrumbs.tpl" currentTitle=$olTitle}
	<h1>{$olTitle|escape}</h1>
	{$olHtml|tdbui nofilter}
</div>
{include file="frontend/components/footer.tpl"}
