<?php
/**
 * فایل callback ماژول زرین پال در WHMCS
 * این فایل بعد از پرداخت (موفق یا ناموفق) توسط زرین پال فراخوانی می‌شود
 * وظیفه دارد وضعیت پرداخت را بررسی کند و در صورت موفقیت، فاکتور را تسویه کند
 */

// فایل‌های اصلی WHMCS برای دسترسی به توابع پایه
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';


/**
 * تابع decodeHash
 * برای رمزگشایی اطلاعاتی که هنگام ایجاد پرداخت رمزگذاری شده‌اند
 * در واقع برعکس تابع encodeHash در فایل اصلی ماژول است
 */
function decodeHash($string){
    // جدول جایگزینی برعکس (برای رمزگشایی)
    $replace = array(
        'Q' => '1',
        'W' => '2',
        'E' => '3',
        'Z' => '4',
        'N' => '5',
        'M' => '6',
        'L' => '7',
        'Y' => '8',
        'U' => '9',
        'D' => '0',
        'V' => ';',
    );

    // مرحله ۱: رمزگشایی Base64
    $decode = base64_decode($string);
    // مرحله ۲: جایگزینی حروف با مقادیر اصلی
    $out = strtr($decode,$replace);
    // مرحله ۳: جدا کردن اطلاعات با ;
    return explode(';',$out);
}


// دریافت اطلاعات رمزگشایی‌شده از پارامتر key در URL
$invData = decodeHash($_REQUEST['key']);

// گرفتن نام ماژول از نام فایل فعلی (برای استفاده در توابع WHMCS)
$gatewayModuleName = basename(__FILE__, '.php');

// دریافت تنظیمات درگاه از بخش مدیریت WHMCS
$gatewayParams = getGatewayVariables($gatewayModuleName);

// متغیرهای مهم از تنظیمات درگاه
$currency = $gatewayParams['currency'];
$merchend = $gatewayParams['merchend'];
$systemurl = $gatewayParams['systemurl'];

// اطلاعات رمزگشایی‌شده (بر اساس ترتیبی که در encodeHash ساخته شده)
$time = $invData[0];
$invoiceId = $invData[1];
$amount = $invData[2];
$uid = $invData[3];

// بررسی فعال بودن ماژول
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}


// اگر واحد پول تومان بود (قابل استفاده در آینده)
if($currency == 'IRT'){
   // در صورت نیاز می‌توان مبلغ را به ریال تبدیل کرد
   // $amount = $amount*10;
}


// تعریف متغیرهای اولیه
$refID = ''; // شماره پیگیری زرین پال
$status = $_GET['status']; // وضعیت بازگشتی از زرین پال (OK یا NOK)

// تعیین وضعیت تراکنش بر اساس پاسخ
if($status == 'OK'){
    $transactionStatus = 'Success';
}else{
    $transactionStatus = 'Failure';
}


// بررسی صحت شماره فاکتور در سیستم WHMCS
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);


// دریافت Authority از پارامتر بازگشتی زرین پال
$Authority = $_GET['Authority'];

// آماده‌سازی داده‌ها برای ارسال درخواست تأیید پرداخت به API زرین پال
$data = array(
    "merchant_id" => $merchend, 
    "authority" => $Authority, 
    "amount" => $amount
);

// ارسال درخواست تأیید پرداخت به API زرین پال با CURL
$jsonData = json_encode($data);
$ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Content-Length: ' . strlen($jsonData)
));

// اجرای درخواست
$result = curl_exec($ch);
curl_close($ch);

// تبدیل پاسخ JSON به آرایه PHP
$result = json_decode($result, true);


// بررسی خطای CURL (در اینجا متغیر $err تعریف نشده است، بهتر است در نسخه نهایی اضافه شود)
if ($err) {
    // در صورت بروز خطا در CURL، پیام خطا نمایش داده می‌شود
    $body = '
        <div style="text-align:center">
            <h2 style="background: #3F51B5;margin-top: -30px;padding: 15px;color: #fff;border-radius: 0px 0px 16px 16px;">خطایی رخ داده است</h2>
            <p style="color:red">شرح خطا : '.$err.'</p>
            <p style="line-height:22px;">با کلیک روی گزینه ارسال گزارش ، درخواستی برای بررسی ارسال میشود.</p>
            <form method="post">
                <input type="hidden" name="actmod" value="report" />
                <textarea name="message" style="display:none">'.$err.'</textarea>
                <button type="submit" class="btn btn-warning btn-sm btn-xs">ارسال گزارش و بازگشت</button>
                <a href="'.$systemurl.'viewinvoice.php?id='.$invoiceId.'" class="btn btn-info btn-sm btn-xs">بازگشت</a>
            </form>
        </div>
    ';
    echo $body;
    die();
} else {
    // اگر پاسخ موفقیت‌آمیز بود و کد 100 برگشت داده شد، پرداخت موفق است
    if ($result['data']['code'] == 100) {
        $transactionStatus = 'Success';
        $refID = $result['data']['ref_id']; // شماره پیگیری تراکنش از زرین پال
    } else {
        $transactionStatus = 'Failure';
    }
}


// ثبت اطلاعات تراکنش در لاگ WHMCS (برای بررسی در آینده)
logTransaction($gatewayModuleName, [
    'Request' => $_REQUEST, 
    'ZpResult' => $result,
    'InvoiceID' => $invoiceId
], $transactionStatus);


// اگر پرداخت موفق نباشد، کاربر به فاکتور بازگردانده می‌شود
if($transactionStatus != 'Success'){
    header('Location: ' . $systemurl . 'viewinvoice.php?id=' . $invoiceId);
    die();
}


// اگر شماره پیگیری خالی بود، کاربر به فاکتور بازگردانده می‌شود
if(empty($refID) || is_null($refID)){
    header('Location: ' . $systemurl . 'viewinvoice.php?id=' . $invoiceId);
    die();
}


// در صورت موفقیت، پرداخت در WHMCS ثبت می‌شود
addInvoicePayment(
    $invoiceId,      // شناسه فاکتور
    $refID,          // شماره پیگیری زرین پال
    $amount,         // مبلغ پرداخت
    0,               // کارمزد (ندارد)
    $gatewayModuleName // نام ماژول پرداخت
);


// در پایان کاربر به صفحه فاکتور بازگردانده می‌شود
header('Location: ' . $systemurl . 'viewinvoice.php?id=' . $invoiceId);
die();
