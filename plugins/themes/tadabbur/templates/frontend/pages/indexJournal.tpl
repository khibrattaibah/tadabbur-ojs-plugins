{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{**
 * Tadabbur Theme 2 — journal home.
 * بطل تعريفي بمؤشرات حيّة ← المجلة في سطور ← العدد الحالي ← السياسات ← الإعلانات ← الفهارس.
 *}
{include file="frontend/components/header.tpl" pageTitleTranslated=$currentJournal->getLocalizedName()}

<div class="page_index_journal tdb-home">
	{call_hook name="Templates::Index::journal"}

	<section class="tdb-hero" aria-labelledby="tdbHeroTitle">
		<div class="tdb-wrap tdb-hero__in">
			<div class="tdb-hero__text">
				<p class="tdb-eyebrow">{if $tdbReviewType}{$tdbReviewType|escape} · {/if}{translate key="plugins.themes.tadabbur.peerReviewedJournal"}</p>
				<h2 id="tdbHeroTitle" class="tdb-hero__title">{$currentContext->getLocalizedName()|escape}</h2>
				{if $tdbTagline}<p class="tdb-hero__tag">{$tdbTagline|escape}</p>{/if}
				{if $currentContext->getLocalizedData('description')}
					<div class="tdb-hero__desc">{$currentContext->getLocalizedData('description')|strip_unsafe_html}</div>
				{/if}
				<div class="tdb-hero__actions">
					<a class="tdb-btn tdb-btn--gold" href="{url page="about" op="submissions"}">{translate key="plugins.themes.tadabbur.submit"}</a>
					{if $issue}<a class="tdb-btn tdb-btn--light" href="#homepageIssue">{translate key="journal.currentIssue"}</a>{/if}
					<a class="tdb-btn tdb-btn--link" href="{url page="about" op="submissions"}#authorGuidelines">{translate key="plugins.themes.tadabbur.guidelines"} ←</a>
				</div>
				{if $tdbStats}
					<dl class="tdb-metrics">
						{foreach from=$tdbStats item=s}
							<div><dt>{$s.label|escape}</dt><dd>{$s.value|escape}</dd></div>
						{/foreach}
					</dl>
				{/if}
			</div>
			<div class="tdb-hero__media">
				{if $issue && $issue->getLocalizedCoverImageUrl()}
					<a class="tdb-covercard" href="{url page="issue" op="view" path=$issue->getBestIssueId()}">
						<img src="{$issue->getLocalizedCoverImageUrl()|escape}" alt="{$issue->getLocalizedCoverImageAltText()|default:''|escape}">
						<span>{translate key="journal.currentIssue"}<b>{$issue->getIssueIdentification()|escape}</b></span>
					</a>
				{elseif $homepageImage}
					<img class="tdb-hero__img" src="{$publicFilesDir}/{$homepageImage.uploadName|escape:"url"}" alt="{$homepageImage.altText|escape}">
				{else}
					<div class="tdb-emblem" aria-hidden="true"><img src="{$tdbLogo}" alt=""></div>
				{/if}
			</div>
		</div>
	</section>

	<section class="tdb-wrap tdb-facts" aria-label="{translate|escape key="plugins.themes.tadabbur.journalFacts"}">
		{if $tdbIssnOnline}<div class="tdb-fact"><span>ISSN (Online)</span><b dir="ltr">{$tdbIssnOnline|escape}</b></div>{/if}
		{if $tdbIssnPrint}<div class="tdb-fact"><span>ISSN (Print)</span><b dir="ltr">{$tdbIssnPrint|escape}</b></div>{/if}
		{if $tdbFrequency}<div class="tdb-fact"><span>{translate key="plugins.themes.tadabbur.frequency"}</span><b>{$tdbFrequency|escape}</b></div>{/if}
		{if $tdbLicenseUrl}<div class="tdb-fact"><span>{translate key="plugins.themes.tadabbur.access"}</span><b>{translate key="plugins.themes.tadabbur.openAccess"}</b></div>{/if}
		{if $tdbApc}<div class="tdb-fact"><span>{translate key="plugins.themes.tadabbur.apc"}</span><b>{$tdbApc|escape}</b></div>{/if}
		{if $tdbPublisher}<div class="tdb-fact"><span>{translate key="plugins.themes.tadabbur.publisher"}</span><b>{$tdbPublisher|escape}</b></div>{/if}
		{if $tdbFounded}<div class="tdb-fact"><span>{translate key="plugins.themes.tadabbur.since"}</span><b>{$tdbFounded|escape}</b></div>{/if}
	</section>

	{if $highlights && $highlights->count()}
		<div class="tdb-wrap">{include file="frontend/components/highlights.tpl" highlights=$highlights}</div>
	{/if}

	{if $issue}
		<section class="tdb-wrap tdb-section current_issue" aria-labelledby="homepageIssueTitle">
			<a id="homepageIssue"></a>
			<header class="tdb-section__head">
				<div><p class="tdb-kicker">{translate key="journal.currentIssue"}</p><h2 id="homepageIssueTitle">{$issue->getIssueIdentification()|escape}</h2></div>
				<a class="tdb-more" href="{url router=PKP\core\PKPApplication::ROUTE_PAGE page="issue" op="archive"}">{translate key="journal.viewAllIssues"} ←</a>
			</header>
			{include file="frontend/objects/issue_toc.tpl" heading="h3" tdbCompact=true}
		</section>
	{/if}

	<section class="tdb-band" aria-labelledby="tdbPoliciesTitle">
		<div class="tdb-wrap tdb-section">
			<header class="tdb-section__head"><div><p class="tdb-kicker">{translate key="plugins.themes.tadabbur.standards"}</p><h2 id="tdbPoliciesTitle">{translate key="plugins.themes.tadabbur.policiesTitle"}</h2></div></header>
			<ul class="tdb-policies">
				{foreach from=$tdbPolicies key=k item=p}
					{if $k != 'privacy'}<li><a class="tdb-policy tdb-ic-{$p[2]|escape}" href="{$p[1]|escape}"><span>{$p[0]|escape}</span></a></li>{/if}
				{/foreach}
			</ul>
		</div>
	</section>

	{if $numAnnouncementsHomepage && $announcements && $announcements|@count}
		<div class="tdb-wrap tdb-section tdb-news">
			{include file="frontend/objects/announcements_list.tpl" numAnnouncements=$numAnnouncementsHomepage}
		</div>
	{/if}

	{if $tdbIndexing}
		<section class="tdb-wrap tdb-section tdb-indexing" aria-labelledby="tdbIndexTitle">
			<header class="tdb-section__head"><div><p class="tdb-kicker">{translate key="plugins.themes.tadabbur.visibility"}</p><h2 id="tdbIndexTitle">{translate key="plugins.themes.tadabbur.indexedIn"}</h2></div></header>
			<ul>{foreach from=$tdbIndexing item=ix}<li>{if $ix.url}<a href="{$ix.url|escape}" rel="noopener" target="_blank">{$ix.name|escape}</a>{else}<span>{$ix.name|escape}</span>{/if}</li>{/foreach}</ul>
		</section>
	{/if}

	{if $additionalHomeContent}<div class="tdb-wrap tdb-section additional_content">{$additionalHomeContent}</div>{/if}
</div>

{include file="frontend/components/footer.tpl"}
