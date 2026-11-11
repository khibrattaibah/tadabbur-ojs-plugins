{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$ilTitle}
<div class="page page_integrity_notices">
	{include file="frontend/components/breadcrumbs.tpl" currentTitle=$ilTitle}
	<h1>{$ilTitle|escape}</h1>
	{$ilHtml|tdbui nofilter}
</div>
{include file="frontend/components/footer.tpl"}
