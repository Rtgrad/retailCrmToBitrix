<?php
require_once __DIR__.'/lib/retail_to_bitix_class.php';

/**
 * Получаем externalID
 */
$id = $_GET[ 'externalId' ];

/**
 * Сопоставление платежных систем из RetailCrm и Bitrix
*/
$pay_system = [
    'code' => [
        'cash' => 1 ,
        'bankpay' => 10 ,
        'sberbankonline' => 15 ,
        'sberbankreceipt' => 7 ,
        'yandexmoney' => 13 ,
        'alfaclick' => 17 ,
        'promsvyazbank' => 18 ,
        'bank-transfer' => 8 ,

    ] ,
    'name' => [
        'cash' => 'Оплата наличными' ,
        'bankpay' => 'Банковские карты.' ,
        'sberbankonline' => 'Сбербанк Онлайн' ,
        'sberbankreceipt' => 'Квитанция Сбербанка' ,
        'yandexmoney' => 'Яндекс Касса' ,
        'alfaclick' => 'Альфа Банк' ,
        'promsvyazbank' => 'Промсвязьбанк' ,
        'bank-transfer' => 'Банковским переводом' ,
    ] ,
    'status' => [
        'not-paid' => 'N' ,
        'paid' => 'Y' ,
    ]
];

/**
 * Сопоставление статусов из RetailCrm и Bitrix
 */
$statuses = [
    'new' => 'NO' ,
    'client-confirmed' => 'CP' ,
    'payok1' => 'PY' , //оплачен -> битрикс
    'cancel-other' => 'CL' ,
    'dostavlen' => 'DL' , //Выполнен -> битрикс
];

/**
 * Сопоставление служб доставок из RetailCrm и Bitrix
 */
$delivery = [
    'id' => [
        '2' => '2' ,
        '22' => '23' ,
        'russian-post' => '24' ,
        'self-delivery' => '3' ,
        'delovyeline' => '27' ,
        'ems' => '26' ,
    ] ,
    'name' => [
        '2' => 'Доставка курьером' ,
        '22' => 'Доставка курьером СДЭК' ,
        'russian-post' => 'Самовывоз из ПВЗ' ,
        'self-delivery' => 'Самовывоз с нашего склада' ,
        'delovyeline' => 'Деловые линии' ,
        'ems' => 'ПЭК' ,
    ]
];

/**
 * Инициализация класса
*/
try {
    $obj = new retToBit( 'https://example.retailcrm.ru' , 'token' , $id );
    $obj -> customLogic ( $id )
        -> changeProducts ( $id )
        -> changeProductsQuantity ( $id )
        -> changeTotalSum ( $id )
        -> сhangeDelivery ( $id , $delivery )
        -> changeStatus ( $id , $statuses )
        -> changePayment ( $id , $pay_system )
        -> clearСache ();
} catch ( Exception $e ) {
    file_put_contents ( 'retailToBitrix_error.log' , $e -> getMessage () , FILE_APPEND );
}

