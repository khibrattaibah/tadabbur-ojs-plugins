{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{**
 * Tadabbur Theme 2 — site header.
 * شريط علوي (ISSN، اللغة، الحساب) ← صف الهوية ← شريط تنقل لاصق بالبحث. متوافق مع بنية قالب OJS الافتراضي (الذي يرث منه).
 *}
{strip}
	{assign var="showingLogo" value=true}
	{if !$displayPageHeaderLogo}{assign var="showingLogo" value=false}{/if}
	{capture assign="homeUrl"}{url page="index" router=PKP\core\PKPApplication::ROUTE_PAGE}{/capture}
{/strip}
<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"}" xml:lang="{$currentLocale|replace:"_":"-"}">
{if !$pageTitleTranslated}{capture assign="pageTitleTranslated"}{translate key=$pageTitle}{/capture}{/if}
{include file="frontend/components/headerHead.tpl"}
<body class="tdb2 pkp_page_{$requestedPage|escape|default:"index"} pkp_op_{$requestedOp|escape|default:"index"}{if $showingLogo} has_site_logo{/if}" dir="{$currentLocaleLangDir|escape|default:"ltr"}">
	<div class="pkp_structure_page">
		<header class="pkp_structure_head tdb-header" id="headerNavigationContainer" role="banner">
			{include file="frontend/components/skipLinks.tpl"}

			<div class="tdb-util">
				<div class="tdb-wrap tdb-util__in">
					<ul class="tdb-util__ids" dir="ltr">
						{if $tdbIssnOnline}<li><abbr title="Online ISSN">e-ISSN</abbr> {$tdbIssnOnline|escape}</li>{/if}
						{if $tdbIssnPrint}<li><abbr title="Print ISSN">p-ISSN</abbr> {$tdbIssnPrint|escape}</li>{/if}
						{if $tdbLicenseUrl}<li class="tdb-util__oa">Open Access</li>{/if}
					</ul>
					<div class="tdb-util__end">
						{if $tdbLocales && $tdbLocales|@count > 1}
							<ul class="tdb-lang" aria-label="{translate|escape key="common.language"}">
								{foreach from=$tdbLocales item=localeName key=localeKey}
									<li><a lang="{$localeKey|replace:"_":"-"}" href="{url router=PKP\core\PKPApplication::ROUTE_PAGE page="user" op="setLocale" path=$localeKey source=$smarty.server.SERVER_NAME|cat:$smarty.server.REQUEST_URI}"{if $localeKey == $currentLocale} aria-current="true"{/if}>{$localeName|escape}</a></li>
								{/foreach}
							</ul>
						{/if}
						<div class="pkp_navigation_user_wrapper tdb-user" id="navigationUserWrapper">
							{load_menu name="user" id="navigationUser" ulClass="pkp_navigation_user" liClass="profile"}
						</div>
					</div>
				</div>
			</div>

			<div class="pkp_head_wrapper tdb-brandrow">
				<div class="tdb-wrap tdb-brandrow__in">
					{if !$requestedPage || $requestedPage === 'index'}
						<h1 class="pkp_screen_reader">{if $currentContext}{$displayPageHeaderTitle|escape}{else}{$siteTitle|escape}{/if}</h1>
					{/if}
					<div class="pkp_site_name_wrapper">
						<div class="pkp_site_name tdb-brand">
							<a href="{$homeUrl}" class="tdb-brand__main">
								{if $displayPageHeaderLogo}
									<img class="tdb-brand__logo" src="{$publicFilesDir}/{$displayPageHeaderLogo.uploadName|escape:"url"}" width="{$displayPageHeaderLogo.width|escape}" height="{$displayPageHeaderLogo.height|escape}" alt="{$displayPageHeaderLogo.altText|default:$displayPageHeaderTitle|escape}">
								{else}
									<img class="tdb-brand__logo" src="{$tdbLogo}" width="314" height="160" alt="{$displayPageHeaderTitle|escape}">
								{/if}
								<span class="tdb-brand__text">
									<span class="tdb-brand__name">{if $currentContext}{$currentContext->getLocalizedName()|escape}{else}{$siteTitle|escape}{/if}</span>
									{if $tdbTagline}<span class="tdb-brand__tag">{$tdbTagline|escape}</span>{/if}
								</span>
							</a>
						</div>
					</div>
					<div class="tdb-brandrow__end">
						{if $currentContext}
							<a class="tdb-cta" href="{url page="about" op="submissions"}">{translate key="plugins.themes.tadabbur.submit"}</a>
						{/if}
						{if $tdbPublisherLogo}
							<a class="tdb-publisher" href="{$tdbPublisherUrl|default:$homeUrl|escape}" {if $tdbPublisherUrl}rel="noopener" target="_blank"{/if} title="{$tdbPublisher|escape}">
								<img src="{$tdbPublisherLogo}" width="237" height="160" alt="{$tdbPublisher|escape}">
							</a>
						{/if}
						<button class="pkp_site_nav_toggle tdb-burger" aria-controls="tdbNav" aria-expanded="false"><span>{translate key="plugins.themes.tadabbur.menu"}</span></button>
					</div>
				</div>
			</div>

			{capture assign="primaryMenu"}{load_menu name="primary" id="navigationPrimary" ulClass="pkp_navigation_primary"}{/capture}
			<nav class="pkp_site_nav_menu tdb-nav" id="tdbNav" aria-label="{translate|escape key="common.navigation.site"}">
				<a id="siteNav"></a>
				<div class="tdb-wrap tdb-nav__in">
					<div class="pkp_navigation_primary_row"><div class="pkp_navigation_primary_wrapper">{$primaryMenu}</div></div>
					{if $currentContext && $requestedPage !== 'search'}
						<form class="tdb-search" action="{url page="search" op="search"}" method="get" role="search">
							<label class="pkp_screen_reader" for="tdbQ">{translate key="common.search"}</label>
							<input id="tdbQ" type="search" name="query" placeholder="{translate|escape key="plugins.themes.tadabbur.searchPh"}">
							<button type="submit" aria-label="{translate|escape key="common.search"}"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M10 2a8 8 0 0 1 6.32 12.9l5.39 5.4-1.41 1.4-5.4-5.39A8 8 0 1 1 10 2Zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12Z"/></svg></button>
						</form>
					{/if}
				</div>
			</nav>
		</header>

		{if $isFullWidth}{assign var=hasSidebar value=0}{/if}
		<div class="pkp_structure_content{if $hasSidebar} has_sidebar{/if}">
			<div class="pkp_structure_main" role="main">
				<a id="pkp_content_main"></a>
