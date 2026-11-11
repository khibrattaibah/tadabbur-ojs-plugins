{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — GPL-3.0-or-later *}
{** Tadabbur Theme 2 — issue card in the archive. *}
{if $issue->getShowTitle()}{assign var=issueTitle value=$issue->getLocalizedTitle()}{/if}
{assign var=issueSeries value=$issue->getIssueSeries()}
{assign var=issueCover value=$issue->getLocalizedCoverImageUrl()}
<div class="obj_issue_summary tdb-icard">
	<a class="cover{if !$issueCover} is-empty{/if}" href="{url op="view" path=$issue->getBestIssueId()}" tabindex="-1" aria-hidden="true">
		{if $issueCover}<img src="{$issueCover|escape}" alt="" loading="lazy">{else}<span><b>{$issue->getNumber()|escape}</b><small dir="ltr">{$issue->getYear()|escape}</small></span>{/if}
	</a>
	<div class="tdb-icard__body">
		<h2><a class="title" href="{url op="view" path=$issue->getBestIssueId()}">{if $issueTitle}{$issueTitle|escape}{else}{$issueSeries|escape}{/if}</a></h2>
		{if $issueTitle && $issueSeries}<div class="series">{$issueSeries|escape}</div>{/if}
		{if $issue->getDatePublished()}<div class="tdb-muted">{$issue->getDatePublished()|date_format:$dateFormatShort}</div>{/if}
		{if $issue->hasDescription()}<div class="description">{$issue->getLocalizedDescription()|strip_unsafe_html|strip_tags|truncate:160}</div>{/if}
	</div>
</div>
