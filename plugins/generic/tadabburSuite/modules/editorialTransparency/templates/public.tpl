{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{**
 * plugins/generic/editorialTransparency/templates/public.tpl
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$etpTitle}
<div class="page page_editorial_transparency">
	{include file="frontend/components/breadcrumbs.tpl" currentTitle=$etpTitle}
	<h1>{$etpTitle|escape}</h1>
	{$etpHtml|tdbui nofilter}
</div>
{include file="frontend/components/footer.tpl"}
