{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — GPL-3.0-or-later *}
{** Tadabbur Theme 2 — issue table of contents (cover strip + sections of article cards). *}
{if !$heading}{assign var="heading" value="h2"}{/if}
{assign var="articleHeading" value="h3"}
{if $heading == "h3"}{assign var="articleHeading" value="h4"}{elseif $heading == "h4"}{assign var="articleHeading" value="h5"}{elseif $heading == "h5"}{assign var="articleHeading" value="h6"}{/if}
<div class="obj_issue_toc tdb-toc">
	{if !$issue->getPublished()}{include file="frontend/components/notification.tpl" type="warning" messageKey="editor.issues.preview"}{/if}

	{if !$tdbCompact}
	<div class="heading tdb-toc__head">
		{assign var=issueCover value=$issue->getLocalizedCoverImageUrl()}
		{if $issueCover}
			<div class="cover">
				{capture assign="defaultAltText"}{translate key="issue.viewIssueIdentification" identification=$issue->getIssueIdentification()|escape}{/capture}
				<img src="{$issueCover|escape}" alt="{$issue->getLocalizedCoverImageAltText()|escape|default:$defaultAltText}">
			</div>
		{/if}
		<div class="tdb-toc__info">
			{if $issue->hasDescription()}<div class="description">{$issue->getLocalizedDescription()|strip_unsafe_html}</div>{/if}
			<dl class="tdb-toc__meta">
				{if $includeIssuePublishDate && $issue->getDatePublished()}<div class="published"><dt>{translate key="submissions.published"}</dt><dd>{$issue->getDatePublished()|date_format:$dateFormatShort}</dd></div>{/if}
				{assign var=doiObject value=$issue->getData('doiObject')}
				{if $doiObject}{assign var="doiUrl" value=$doiObject->getData('resolvingUrl')|escape}<div class="pub_id doi"><dt>DOI</dt><dd><a dir="ltr" href="{$doiUrl}">{$doiUrl}</a></dd></div>{/if}
				{foreach from=$pubIdPlugins item=pubIdPlugin}
					{assign var=pubId value=$issue->getStoredPubId($pubIdPlugin->getPubIdType())}
					{if $pubId}{assign var="resolvingUrl" value=$pubIdPlugin->getResolvingURL($currentJournal->getId(), $pubId)|escape}<div class="pub_id {$pubIdPlugin->getPubIdType()|escape}"><dt>{$pubIdPlugin->getPubIdDisplayType()|escape}</dt><dd>{if $resolvingUrl}<a href="{$resolvingUrl}">{$resolvingUrl}</a>{else}{$pubId}{/if}</dd></div>{/if}
				{/foreach}
			</dl>
			{if $issueGalleys}
				<div class="galleys">
					<span id="issueTocGalleyLabel" class="tdb-muted">{translate key="issue.fullIssue"}</span>
					<ul class="galleys_links">
						{foreach from=$issueGalleys item=galley}<li>{include file="frontend/objects/galley_link.tpl" parent=$issue labelledBy="issueTocGalleyLabel" purchaseFee=$currentJournal->getData('purchaseIssueFee') purchaseCurrency=$currentJournal->getData('currency')}</li>{/foreach}
					</ul>
				</div>
			{/if}
		</div>
	</div>
	{/if}

	<div class="sections">
		{foreach name=sections from=$publishedSubmissions item=section}
			{if $section.articles}
				<section class="section tdb-toc__section">
					{if $section.title}<{$heading} class="tdb-toc__stitle"><span>{$section.title|escape}</span><small>{$section.articles|@count}</small></{$heading}>{/if}
					<ol class="cmp_article_list articles">
						{foreach from=$section.articles item=article}<li>{include file="frontend/objects/article_summary.tpl" heading=$articleHeading}</li>{/foreach}
					</ol>
				</section>
			{/if}
		{/foreach}
	</div>
</div>
