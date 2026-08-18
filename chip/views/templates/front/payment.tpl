{*
* CHIP for PrestaShop 1.6 - payment method button on the order page.
*}

<div class="row">
	<div class="col-xs-12">
		{if $chip_error}
			<div class="alert alert-danger">
				{$chip_error|escape:'html':'UTF-8'}
			</div>
		{/if}
		<div class="payment_module">
			<a class="chip" href="{$chip_payment_url}" title="{$chip_module_name|escape:'html':'UTF-8'}">
				<img src="{$chip_logo}" alt="{$chip_module_name|escape:'html':'UTF-8'}" style="max-height: 32px; vertical-align: middle; margin-right: 8px;" />
				{$chip_module_name|escape:'html':'UTF-8'}
				{if $chip_methods|@count > 0}
					<br />
					<small>{foreach $chip_methods as $method}{$method|escape:'html':'UTF-8'}{if !$method@last} &middot; {/if}{/foreach}</small>
				{/if}
			</a>
		</div>
	</div>
</div>
