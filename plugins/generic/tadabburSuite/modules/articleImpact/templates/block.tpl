{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
<div class="item article_impact" id="article-impact">
	<h2 class="label">{translate key="plugins.generic.articleImpact.blockTitle"}</h2>
	<div class="value">
		<ul style="list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem;text-align:center">
			<li style="background:#f6f1e7;border-radius:8px;padding:.5rem .2rem"><div style="font-size:1.35rem;font-weight:700">{$aiViews|escape}</div><div style="font-size:.8rem">{translate key="plugins.generic.articleImpact.views"}</div></li>
			<li style="background:#f6f1e7;border-radius:8px;padding:.5rem .2rem"><div style="font-size:1.35rem;font-weight:700">{$aiDownloads|escape}</div><div style="font-size:.8rem">{translate key="plugins.generic.articleImpact.downloads"}</div></li>
			<li style="background:#f6f1e7;border-radius:8px;padding:.5rem .2rem"><div style="font-size:1.35rem;font-weight:700">{$aiCitations|escape}</div><div style="font-size:.8rem">{if $aiHasCiting}<a href="#cited-by">{translate key="plugins.generic.articleImpact.citations"}</a>{else}{translate key="plugins.generic.articleImpact.citations"}{/if}</div></li>
		</ul>
		{if $aiChart}
			<div style="margin-top:.6rem"><div style="font-size:.8rem;color:#6b625a;margin-bottom:.2rem">{translate key="plugins.generic.articleImpact.chartLabel"}</div>{$aiChart nofilter}</div>
		{/if}
		{if $aiDoi && ($aiAltmetric || $aiDimensions)}
			<div style="display:flex;gap:.6rem;align-items:center;margin-top:.6rem;flex-wrap:wrap">
				{if $aiAltmetric}<div class="altmetric-embed" data-badge-type="donut" data-badge-popover="left" data-hide-no-mentions="true" data-doi="{$aiDoi|escape}"></div><script async src="https://d1bxh8uas1mnw7.cloudfront.net/assets/embed.js"></script>{/if}
				{if $aiDimensions}<span class="__dimensions_badge_embed__" data-doi="{$aiDoi|escape}" data-style="small_circle" data-hide-zero-citations="true"></span><script async src="https://badge.dimensions.ai/badge.js" charset="utf-8"></script>{/if}
			</div>
		{/if}
		<div style="font-size:.72rem;color:#8a8076;margin-top:.4rem">{translate key="plugins.generic.articleImpact.sourceNote"}</div>
	</div>
</div>
