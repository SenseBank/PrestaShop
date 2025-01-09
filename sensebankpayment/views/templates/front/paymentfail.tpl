{extends file=$layout}
{block name="content_wrapper"}
    {block name="content"}
        {capture name=path}
            <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'htmlall':'UTF-8'}"
               title="{l s='Go back to the Checkout' mod='sensebankpayment'}">{l s='Checkout' mod='sensebankpayment'}</a>
            <span class="navigation-pipe">{$navigationPipe|escape:'htmlall':'UTF-8'}</span>{l s='Payment by bank card' mod='sensebankpayment'}
        {/capture}
        <h1 class="page-heading">{l s='Order payment error!' mod='sensebankpayment'}</h1>
        <div class="box cheque-box">
            <p>
                {l s='An error occurred during the payment! contact the store manager.' mod='sensebankpayment'}
                <br />
                {$order_error_code}: {$order_error_message}
            </p>


        </div>
        <!-- p class="cart_navigation clearfix" id="cart_navigation">
    <a class="{if $presta15}button_large{else}button-exclusive btn btn-default{/if}" href="{$link->getPageLink('index')|escape:'htmlall':'UTF-8'}">
        <i class="icon-chevron-left"></i>{l s='Return to store' mod='sensebankpayment'}
    </a>
</p-->
    {/block}
{/block}
