{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$bpTitle}
<div class="page page_board">
	{include file="frontend/components/breadcrumbs.tpl" currentTitle=$bpTitle}
	<h1>{$bpTitle|escape}</h1>
	{$bpHtml|tdbui nofilter}
</div>
{include file="frontend/components/footer.tpl"}
