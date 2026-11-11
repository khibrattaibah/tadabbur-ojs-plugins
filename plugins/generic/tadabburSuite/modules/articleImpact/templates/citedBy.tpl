{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<section class="item cited_by" id="cited-by" style="margin-top:1.5rem">
	<h2 class="label">{translate key="plugins.generic.articleImpact.citedByTitle" n=$aiCitingTotal}</h2>
	<ol style="margin:0;padding-inline-start:1.3rem">
		{foreach from=$aiCiting item=c}
			<li style="margin:.45rem 0">
				{if $c.authors}<span>{$c.authors|escape}</span>{if $c.year} ({$c.year|escape}){/if}. {/if}
				{if $c.doi}<a href="https://doi.org/{$c.doi|escape}" target="_blank" rel="noopener"><strong>{$c.title|escape}</strong></a>{else}<strong>{$c.title|escape}</strong>{/if}{if $c.source}. <em>{$c.source|escape}</em>{/if}
			</li>
		{/foreach}
	</ol>
	<div style="font-size:.8rem;color:#8a8076">{translate key="plugins.generic.articleImpact.citedByNote"}</div>
</section>
