<?php
    use MapasCulturais\App;
    use MapasCulturais\i;

    $route = App::i()->createUrl('paymentauxilio', 'payment');
?>

<!--botão de imprimir-->
<a class="btn btn-default download" ng-click="editbox.open('payment-auxilio', $event)" rel="noopener noreferrer">Gerar Pagamento</a>

<!-- Formulário -->
<edit-box id="payment-auxilio" position="top" title="<?php i::esc_attr_e('Gerar Pagamento') ?>" cancel-label="Cancelar" close-on-cancel="true">
    <form class="form-payment-auxilio" action="<?= $route ?>" method="POST">
        <label for="paymentDate">Data do pagamento</label>
        <input type="date" name="paymentDate" id="paymentDate">
        <label for="from">Parcela</label>
        <select name="installment" id="installment">
            <option value="1" selected>1</option>
            <option value="2">2</option>
        </select>
        <label for="from">Refazer pagamento?</label>
        <select name="remakePayment" id="remakePayment">
            <option value="1" selected>SIM</option>
            <option value="0" selected>NÃO</option>
        </select>
        <label for="from">Inscrições</label>
        <textarea name="registrations" id="registrations" cols="30" rows="2" placeholder="Separe por ponto e virgula e sem prefixo Ex.: 1256584;6941216854"></textarea>
        <input type="hidden" name="opportunity" value="<?= $opportunity->id ?>">
        <button class="btn btn-primary download" type="submit">Gerar Pagamento</button>
    </form>
</edit-box>