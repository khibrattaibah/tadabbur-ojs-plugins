{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — GPL-3.0-or-later *}
{** Tadabbur Theme 2 — article card in tables of contents and lists. *}
{assign var=publication value=$article->getCurrentPublication()}
{assign var=articlePath value=$publication->getData('urlPath')|default:$article->getId()}
{if !$heading}{assign var="heading" value="h2"}{/if}
{if (!$section.hideAuthor && $publication->getData('hideAuthor') == APP\submission\Submission::AUTHOR_TOC_DEFAULT) || $publication->getData('hideAuthor') == APP\submission\Submission::AUTHOR_TOC_SHOW}{assign var="showAuthor" value=true}{/if}
{if $journal}{capture assign=artUrl}{url router=PKP\core\PKPApplication::ROUTE_PAGE journal=$journal->getPath() page="article" op="view" path=$articlePath}{/capture}{else}{capture assign=artUrl}{url router=PKP\core\PKPApplication::ROUTE_PAGE page="article" op="view" path=$articlePath}{/capture}{/if}
<article class="obj_article_summary tdb-acard">
	{if $publication->getLocalizedData('coverImage')}
		{assign var="coverImage" value=$publication->getLocalizedData('coverImage')}
		<a class="cover" href="{$artUrl}"><img src="{$publication->getLocalizedCoverImageUrl($article->getData('contextId'))|escape}" alt="{$coverImage.altText|escape|default:''}"></a>
	{/if}
	<div class="tdb-acard__body">
		<{$heading} class="title">
			<a id="article-{$article->getId()}" href="{$artUrl}">
				{if $currentContext}
					{$publication->getLocalizedTitle(null, 'html')|strip_unsafe_html}
					{assign var=localizedSubtitle value=$publication->getLocalizedSubtitle(null, 'html')|strip_unsafe_html}
					{if $localizedSubtitle}<span class="subtitle">{$localizedSubtitle}</span>{/if}
				{else}
					{$publication->getLocalizedFullTitle(null, 'html')|strip_unsafe_html}<span class="subtitle">{$journal->getLocalizedName()|escape}</span>
				{/if}
			</a>
		</{$heading}>
		{assign var=submissionPages value=$publication->getData('pages')}
		{assign var=submissionDatePublished value=$publication->getData('datePublished')}
		<div class="meta">
			{if $showAuthor}<div class="authors">{$publication->getAuthorString($authorUserGroups)|escape}</div>{/if}
			<ul class="tdb-acard__facts">
				{if $submissionPages}<li class="pages">{translate key="plugins.themes.tadabbur.pagesShort"} <span dir="ltr">{$submissionPages|escape}</span></li>{/if}
				{if $showDatePublished && $submissionDatePublished}<li class="published">{$submissionDatePublished|date_format:$dateFormatShort}</li>{/if}
				{if $publication->getDoi()}<li class="tdb-acard__doi"><a dir="ltr" href="https://doi.org/{$publication->getDoi()|escape}">doi.org/{$publication->getDoi()|escape}</a></li>{/if}
			</ul>
		</div>
		{if !$hideGalleys}
			<ul class="galleys_links">
				{foreach from=$publication->getData('galleys') item=galley}
					{if $primaryGenreIds}
						{assign var="file" value=$galley->getFile()}
						{if !$galley->getData('urlRemote') && !($file && in_array($file->getGenreId(), $primaryGenreIds))}{continue}{/if}
					{/if}
					<li>
						{assign var="hasArticleAccess" value=$hasAccess}
						{if $currentContext->getSetting('publishingMode') == APP\journal\Journal::PUBLISHING_MODE_OPEN || $publication->getData('accessStatus') == APP\submission\Submission::ARTICLE_ACCESS_OPEN}{assign var="hasArticleAccess" value=1}{/if}
						{assign var="id" value="article-{$article->getId()}-galley-{$galley->getId()}"}
						{include file="frontend/objects/galley_link.tpl" parent=$article publication=$publication id=$id labelledBy="{$id} article-{$article->getId()}" hasAccess=$hasArticleAccess purchaseFee=$currentJournal->getData('purchaseArticleFee') purchaseCurrency=$currentJournal->getData('currency')}
					</li>
				{/foreach}
				<li><a class="tdb-acard__read" href="{$artUrl}">{translate key="plugins.themes.tadabbur.readAbstract"}</a></li>
			</ul>
		{/if}
		{call_hook name="Templates::Issue::Issue::Article"}
	</div>
</article>
