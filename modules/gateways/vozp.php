<?php
// جلوگیری از دسترسی مستقیم به فایل (برای امنیت)
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// استفاده از کلاس Capsule برای کار با دیتابیس در WHMCS
use Illuminate\Database\Capsule\Manager as Capsule;


/**
 * اطلاعات متادیتا ماژول پرداخت زرین پال
 * این بخش نام، نسخه API و تنظیمات پایه ماژول را برمی‌گرداند
 */
function vozp_MetaData()
{
    return array(
        'DisplayName' => 'پرداخت زرین پال', // نام ماژول در لیست درگاه‌ها
        'APIVersion' => '1.0', // نسخه API
        'DisableLocalCreditCardInput' => true, // غیرفعال‌سازی فرم کارت بانکی داخلی
        'TokenisedStorage' => false, // غیرفعال بودن ذخیره توکن کارت
    );
}


/**
 * تنظیمات ماژول در پنل مدیریت WHMCS
 * در این بخش گزینه‌هایی مثل مرچنت کد، واحد پول و دپارتمان گزارش خطا تعریف می‌شوند
 */
function vozp_config()
{
    // دریافت لیست دپارتمان‌های تیکت از دیتابیس
    $depts = Capsule::table('tblticketdepartments')->select('id','name')->get();
    $listsDepartmants = [];
    foreach($depts as $item){
        $listsDepartmants[$item->id] = $item->name;
    }
    
    // بازگرداندن تنظیمات ماژول
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'ماژول پرداخت زرین پال', // نام قابل نمایش در تنظیمات درگاه
        ),
        'merchend' => array(
            'FriendlyName' => 'مرچند کد', // کد پذیرنده زرین پال
            'Type' => 'text',
        ),
        'currency' => array(
            'FriendlyName' => 'واحد پول', // نوع ارز پرداخت
            'Type' => 'dropdown',
            'Options' => array(
                'IRT' => 'تومان',
                'IRR' => 'ریال',
            )
        ),
        'depId' => array(
            'FriendlyName' => 'دپارتمان ارسال گزارش خطا', // بخش ارسال خطاها
            'Type' => 'dropdown',
            'Options' => $listsDepartmants
        ),
    );
}


/**
 * تابع ساخت قالب HTML برای نمایش خطاها و پیام‌ها
 */
function htmlthemplate($title,$body){
    return '<!DOCTYPE html>
    <html lang="fa">
        <head>
            <title>'.$title.'</title>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
            <style>
                body{
                    direction:rtl;
                    font-family:tahoma;
                    font-size:12px;
                    padding-top:200px;
                    line-height:37px;
                    background: #cfcfcf6e;
                }
                h1,h2,h3,h4,h5,h6{
                    font-weight: 900;
                    font-size: 16px;
                    color: #3F51B5;
                    margin-bottom: 15px;
                }
            </style>
        </head>
            <body>
                <div class="container" style="width: 400px;border: solid 2px #3F51B5;padding: 30px;border-radius: 6px;background: #fff;">'.$body.'</div>
            </body>
    </html>';
}


/**
 * تابع رمزگذاری (Encode) برای تولید کلید امن در آدرس بازگشتی (Callback)
 */
function encodeHash($string){
    // جایگزینی کاراکترهای خاص با حروف مشخص (برای امنیت و یکتا بودن)
    $replace = array(
        '1' => 'Q',
        '2' => 'W',
        '3' => 'E',
        '4' => 'Z',
        '5' => 'N',
        '6' => 'M',
        '7' => 'L',
        '8' => 'Y',
        '9' => 'U',
        '0' => 'D',
        ';' => 'V',
    );
    // اعمال جایگزینی
    $string = strtr($string,$replace);
    // رمزگذاری Base64
    $out = base64_encode($string);
    return $out;
}



/**
 * تابع اصلی لینک پرداخت (نمایش دکمه پرداخت، مدیریت درخواست‌ها و خطاها)
 */
function vozp_link($params)
{
    // متغیرهای تنظیمات درگاه پرداخت
    $merchend = $params['merchend'];
    $currency = $params['currency'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    
    // متغیرهای مربوط به فاکتور
    $invoiceId = $params['invoiceid'];
    $s_amount = $params['amount'];
    $amount = intval($s_amount); // تبدیل مبلغ به عدد صحیح
    $currency = $params['currency'];
    $depId = $params['depId'];
    
    // شناسه کاربر فعلی
    $uid = $params['clientdetails']['id'];
    
    // بررسی ارسال فرم
    if ($_SERVER['REQUEST_METHOD'] == 'POST'){
        
        // تعیین اکشن (عملیات درخواستی)
        $action = $_POST['actmod'] ?: 'canceled';
        
        
        /**
         * بخش پرداخت (payment)
         * ایجاد تراکنش جدید و اتصال به درگاه زرین پال
         */
        if($action == 'payment'){
            // ایجاد کلید رمزگذاری‌شده شامل زمان، شماره فاکتور، مبلغ و شناسه کاربر
            $string = time() . ";{$invoiceId};{$amount};{$uid}";
            $key = encodeHash($string);
            
            // داده‌های ارسالی به API زرین پال
            $data = array(
                "merchant_id" => $merchend,
                "amount" => $amount,
                "currency" => $currency,
                "callback_url" => $systemUrl . '/modules/gateways/callback/vozp.php?key='.$key,
                "description" => "InvoicdID : {$invoiceId}"
            );

            // ارسال درخواست به زرین پال با CURL
            $jsonData = json_encode($data);
            $ch = curl_init('https://payment.zarinpal.com/pg/v4/payment/request.json');
            curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v1');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonData)
            ));

            $result = curl_exec($ch);
            $err = curl_error($ch);
            $result = json_decode($result, true, JSON_PRETTY_PRINT);
            curl_close($ch);
            
            // بررسی خطاها در ارتباط CURL
            if ($err) {
                // نمایش پیام خطا و امکان ارسال گزارش
                $body = '
                    <div style="text-align:center">
                        <h2 style="background: #3F51B5;margin-top: -30px;padding: 15px;color: #fff;border-radius: 0px 0px 16px 16px;">خطایی رخ داده است</h2>
                        <p style="color:red">شرح خطا : '.$err.'</p>
                        <p style="line-height:22px;">با کلیک روی گزینه ارسال گزارش، خطا برای پشتیبانی ارسال می‌شود.</p>
                        <form method="post">
                            <input type="hidden" name="actmod" value="report" />
                            <input type="hidden" name="message" value="متن خطا : '.$err.'" />
                            <button type="submit" class="btn btn-warning btn-sm btn-xs">ارسال گزارش و بازگشت</button>
                            <a href="'.$systemUrl.'viewinvoice.php?id='.$invoiceId.'" class="btn btn-info btn-sm btn-xs">بازگشت</a>
                        </form>
                    </div>
                ';
                echo htmlthemplate('خطا رخ داده است',$body);
                die();
                
            } else {
                // بررسی نتیجه بازگشتی از زرین پال
                if (empty($result['errors'])) {
                    if ($result['data']['code'] == 100) {
                        // هدایت کاربر به صفحه پرداخت زرین پال
                        header('Location: https://www.zarinpal.com/pg/StartPay/' . $result['data']["authority"]);
                        die();
                    }
                } else {
                    // اگر خطایی از طرف زرین پال برگشت داده شد
                    $body = '
                        <div style="text-align:center">
                            <h2 style="background: #3F51B5;margin-top: -30px;padding: 15px;color: #fff;border-radius: 0px 0px 16px 16px;">خطایی رخ داده است</h2>
                            <p style="color:red">شرح خطا : '.$result['errors']['message'].'</p>
                            <p style="line-height:22px;">با کلیک روی گزینه ارسال گزارش، خطا برای پشتیبانی ارسال می‌شود.</p>
                            <form method="post">
                                <input type="hidden" name="actmod" value="report" />
                                <textarea name="message" style="display:none;">Error : '.json_encode($result).'</textarea>
                                <button type="submit" class="btn btn-warning btn-sm btn-xs">ارسال گزارش و بازگشت</button>
                                <a href="'.$systemUrl.'viewinvoice.php?id='.$invoiceId.'" class="btn btn-info btn-sm btn-xs">بازگشت</a>
                            </form>
                        </div>
                    ';
                    echo htmlthemplate('خطا رخ داده است',$body);
                    die();
                }
            }
        }
        
        
        /**
         * بخش ارسال گزارش خطا (report)
         * ایجاد تیکت پشتیبانی در دپارتمان مشخص شده
         */
        if($action == 'report'){
            if(empty($_POST['message'])){
                header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId);
                die();
            }
            $msg = $_POST['message'];
            $postData = array(
                'deptid' => $depId,
                'subject' => 'گزارش خطای پرداخت',
                'message' => $msg,
                'clientid' => $uid,
                'priority' => 'Medium',
            );
            // ایجاد تیکت جدید با استفاده از API داخلی WHMCS
            localAPI('OpenTicket', $postData);
            header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId);
            die();
        }
    }

    // نمایش دکمه پرداخت در صفحه فاکتور
    $htmlOutput = '<form method="post">';
    $htmlOutput .= '<input type="hidden" name="actmod" value="payment" />';
    $htmlOutput .= '<input type="submit" class="btn btn-info btn-xl cursor-pointer" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
