{* Copyright (c) 2026 Moustafa Mahmoud & Khibrat Taibah for Research and Studies — https://github.com/mostafamr2011 · https://khibrattaibah.com — GPL-3.0-or-later *}
{**
 * templates/frontend/components/footer.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Common site frontend footer.
 *
 * @uses $isFullWidth bool Should this page be displayed without sidebars? This
 *       represents a page-level override, and doesn't indicate whether or not
 *       sidebars have been configured for thesite.
 *
 * @hook Templates::Common::Sidebar []
 * @hook Templates::Common::Footer::PageFooter []
 *}

	</div><!-- pkp_structure_main -->

	{* Sidebars *}
	{if empty($isFullWidth)}
		{capture assign="sidebarCode"}{call_hook name="Templates::Common::Sidebar"}{/capture}
		{if $sidebarCode}
			<div class="pkp_structure_sidebar left" role="complementary">
				{$sidebarCode}
			</div><!-- pkp_sidebar.left -->
		{/if}
	{/if}
</div><!-- pkp_structure_content -->

<div class="pkp_structure_footer_wrapper tdb-footer" role="contentinfo">
	<a id="pkp_content_footer"></a>
	<div class="pkp_structure_footer">
		<div class="tdb-wrap tdb-footer__grid">
			<section class="tdb-footer__about">
				<img src="{$tdbLogo}" alt="" width="157" height="80" loading="lazy" class="tdb-footer__logo">
				<h2>{if $currentContext}{$currentContext->getLocalizedName()|escape}{else}{$siteTitle|escape}{/if}</h2>
				{if $tdbTagline}<p>{$tdbTagline|escape}</p>{/if}
				<ul class="tdb-footer__ids" dir="ltr">
					{if $tdbIssnOnline}<li>e-ISSN {$tdbIssnOnline|escape}</li>{/if}
					{if $tdbIssnPrint}<li>p-ISSN {$tdbIssnPrint|escape}</li>{/if}
				</ul>
			</section>
			<nav aria-label="{translate|escape key="plugins.themes.tadabbur.policies"}">
				<h2>{translate key="plugins.themes.tadabbur.policies"}</h2>
				<ul class="tdb-footer__links">{foreach from=$tdbPolicies key=k item=p}<li><a href="{$p[1]|escape}">{$p[0]|escape}</a></li>{/foreach}</ul>
			</nav>
			<nav aria-label="{translate|escape key="plugins.themes.tadabbur.forAuthors"}">
				<h2>{translate key="plugins.themes.tadabbur.forAuthors"}</h2>
				<ul class="tdb-footer__links">
					<li><a href="{url page="about" op="submissions"}">{translate key="plugins.themes.tadabbur.submit"}</a></li>
					<li><a href="{url page="issue" op="current"}">{translate key="journal.currentIssue"}</a></li>
					<li><a href="{url page="issue" op="archive"}">{translate key="navigation.archives"}</a></li>
					<li><a href="{url page="search"}">{translate key="common.search"}</a></li>
					<li><a href="{url page="gateway" op="plugin" path="WebFeedGatewayPlugin"|cat:"/atom"}">RSS / Atom</a></li>
				</ul>
			</nav>
			<section>
				<h2>{translate key="plugins.themes.tadabbur.contactUs"}</h2>
				{if $currentContext}
					{if $currentContext->getData('mailingAddress')}<p>{$currentContext->getData('mailingAddress')|escape|nl2br}</p>{/if}
					{if $currentContext->getData('contactEmail')}<p><a href="mailto:{$currentContext->getData('contactEmail')|escape}" dir="ltr">{$currentContext->getData('contactEmail')|escape}</a></p>{/if}
					<p><a class="tdb-footer__btn" href="{url page="about" op="contact"}">{translate key="plugins.themes.tadabbur.contactPage"}</a></p>
				{/if}
				{if $tdbLicenseUrl}<p class="tdb-footer__license">{translate key="plugins.themes.tadabbur.licenseNote"} <a href="{$tdbLicenseUrl|escape}" rel="license noopener" target="_blank" dir="ltr">{$tdbLicenseUrl|replace:"https://creativecommons.org/licenses/":"CC "|replace:"/":" "|trim|upper|escape}</a></p>{/if}
			</section>
		</div>
		{if $tdbIndexing}
			<div class="tdb-wrap tdb-footer__index"><span>{translate key="plugins.themes.tadabbur.indexedIn"}</span><ul>{foreach from=$tdbIndexing item=ix}<li>{if $ix.url}<a href="{$ix.url|escape}" rel="noopener" target="_blank">{$ix.name|escape}</a>{else}{$ix.name|escape}{/if}</li>{/foreach}</ul></div>
		{/if}
		{if $pageFooter}<div class="tdb-wrap pkp_footer_content">{$pageFooter}</div>{/if}
		<div class="tdb-footer__bottom"><div class="tdb-wrap tdb-footer__bottom-in">
			<span>© {$smarty.now|date_format:"Y"} {if $tdbPublisher}{$tdbPublisher|escape}{/if}</span>
			<span>{translate key="plugins.themes.tadabbur.developedBy"} <a href="https://github.com/mostafamr2011" rel="noopener" target="_blank">{translate key="plugins.themes.tadabbur.developer"}</a> · <a href="https://khibrattaibah.com" rel="noopener" target="_blank">{translate key="plugins.themes.tadabbur.office"}</a></span>
			<a href="{url page="about" op="aboutThisPublishingSystem"}">{translate key="about.aboutThisPublishingSystem"}</a>
		</div></div>
	</div>
</div><!-- pkp_structure_footer_wrapper -->

</div><!-- pkp_structure_page -->

{load_script context="frontend"}

{call_hook name="Templates::Common::Footer::PageFooter"}
</body>
</html>
