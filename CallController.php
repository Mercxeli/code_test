<?php

namespace app\controllers;

use Yii;
use app\models\Contact;
use app\models\Phones;
use app\models\Call;
use app\models\Results;
use app\models\DynamicPage;
use app\models\ContactBlockHistory;
use app\models\ContactApperances;
use app\models\Scenario;
use yii\helpers\Url;
use app\models\Inquirie;
use app\models\ContactInfo;

/**
 * 
 */
class CallController extends \yii\web\Controller
{
    /**
     * @var string Шаблон основной
     */
    public $layout = 'call';

    public function actionIndex()
    {
        Yii::$app->getSession()->remove('idCall');
        Yii::$app->getSession()->remove('idContact');
        $contact = null;
        if (Yii::$app->params['isDialer']) {
            $contact = Contact::findByDialer();
        } else {
            $contact = Contact::findByManual();
        }
        $phone       = Phones::getPhoneByRequest($contact->ID_CONTACT);
        $inquirie    = Inquirie::getInquirieByContactId($contact->ID_CONTACT);
        $contactInfo = ContactInfo::findOne(['FID_CONTACT' => $contact->ID_CONTACT]);
        $lastResult  = Call::getLastCall($contact->ID_CONTACT);
        $params      = Yii::$app->request->getQueryParams();

        unset($params['r']); // К сожалению пока нету метода на получение параметров без роутера
        $startTime   = date('d.m.Y H:i:s');
        $urlParams   = array_merge(['call/answer'], $params, ['start_time' => $startTime]);
        $urlToStatus = array_merge(['call/set-call-status'], $params, ['start_time' => $startTime]);

        $urlWithGetParams = Url::to($urlParams);
        return $this->render('index',
                [
                'phone' => $phone,
                'params' => $params,
                'contact' => $contact,
                'inquirie' => $inquirie,
                'lastResult' => $lastResult,
                'comments' => Call::getLastComments($contact->ID_CONTACT),
                'contactInfo' => $contactInfo,
                'urlToStatus' => $urlToStatus,
                'resultButtons' => Results::getButtonForCallIndex(),
                'urlWithGetParams' => $urlWithGetParams,
        ]);
    }

    /**
     * @return type
     */
    public function actionAnswer()
    {
        $this->layout = 'call';
        $session      = Yii::$app->getSession();
        if (Yii::$app->params['isDialer']) {
            $contact = Contact::findByDialer();
        } else {
            $contact = Contact::findByManual();
        }
        $phone  = Phones::getPhoneByRequest($contact->ID_CONTACT);
        $result = Results::findOne(['RESULT_NAME' => "В работе"]);
        $call   = Call::initCall($contact->ID_CONTACT, $phone->ID_PHONE, $result->ID_RESULT);

        ContactApperances::registrExitContact($contact->ID_CONTACT, $call->ID_CALL);

        /**
         * @todo Прибраться в коде
         */
        $contact->setBlock($result->CONTACT_FID_BLOCK);
        $call->setResult($result->ID_RESULT);
        $call->save();
        $contact->FID_LAST_CALL = $call->ID_CALL;
        $contact->save();
        $phone->save();
        /**
         * END TODO!
         */
        $session->set('idContact', $contact->ID_CONTACT);
        $session->set('idCall', $call->ID_CALL);
        $scenario               = Scenario::findOne(['ID_SCENARIO' => $contact->FID_SCENARIO]);

        $inquirie    = Inquirie::getInquirieByContactId($contact->ID_CONTACT);
        $contactInfo = ContactInfo::findOne(['FID_CONTACT' => $contact->ID_CONTACT]);
        $lastResult  = Call::getLastCall($contact->ID_CONTACT);

        $dynPages = DynamicPage::getDb()->cache(function($db) use ($contact) {
            return DynamicPage::find()->where(['or',
                        ['FID_SCENARIO' => $contact->FID_SCENARIO],
                        ['FID_SCENARIO' => null]])
                    ->orderBy(['PAGE_ORDER' => SORT_ASC])->all();
        }, 6000);
        return $this->render($scenario->TEMPLATE_NAME,
                [
                'call' => $call,
                'pages' => $dynPages,
                'phone' => $phone,
                'contact' => $contact,
                'inquirie' => $inquirie,
                'scenario' => $scenario,
                'lastResult' => $lastResult,
                'contactInfo' => $contactInfo,
        ]);
    }

    /**
     * Метод для установки статуса звонка на интерфейсе "Звонок"
     * @param integer $status ИД Результата
     * @return JSON Возвращает массив с результатами сохранения всех параметров
     */
    public function actionSetCallStatus($status)
    {
        $contact = Contact::findByDialer();
        $result  = Results::findOne(['ID_RESULT' => $status]);
        $phone   = Phones::getPhoneByRequest($contact->ID_CONTACT);
        $call    = Call::initCall($contact->ID_CONTACT, $phone->ID_PHONE, $result->ID_RESULT);

        $call->setResult($result->ID_RESULT, $result->CALL_BACK_INTERVAL);
        /**
         * @todo Проверить как работает скрипт
         */
        $phone->incCallAmount();
        $phone->setBlock($result->PHONE_FID_BLOCK);
        if ($phone->allPhonesIsLimitAttempts()) {
            $result->CONTACT_FID_BLOCK = 1;
        }
        /**
         * END
         */
        $contact->setBlock($result->CONTACT_FID_BLOCK, $result->CALL_BACK_INTERVAL);

        $contactStatus = $contact->save();
        $phoneStatus   = $phone->save();
        $callStatus    = $call->save();

        ContactBlockHistory::log($contact->ID_CONTACT, $call->ID_CALL, $contact->FID_BLOCK);
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        return [
            'callSave' => $callStatus,
            'contactSave' => $contactStatus,
            'phoneSave' => $phoneStatus,
            'contactError' => $contact->getFirstErrors()
        ];
    }

    /**
     * Метод для установки перезвона
     * @todo сделать form отдельный для данного экшена
     */
    public function actionSetRecall()
    {
        if (Yii::$app->request->isAjax) {

            $callForm  = Call::loadFromForm(); /* new Call(Yii::$app->request->post()) */;
            $phoneForm = Phones::loadFromForm(); /* new Phones(Yii::$app->request->post()) */;

            $call    = Call::findOne(['ID_CALL' => $callForm->ID_CALL]);
            $phone   = Phones::getPhone($phoneForm->PHONE_NUMBER, $call->FID_CONTACT);
            $result  = Results::findOne(['RESULT_GROUP' => 'callback']);
            $contact = Contact::findOne(['ID_CONTACT' => $call->FID_CONTACT]);

            $call->setRecall($callForm, $phone->ID_PHONE, $result->ID_RESULT);
            $contact->setRecall($result->CONTACT_FID_BLOCK, $call->CALL_BACK_TIME);

            $contactSave                 = $contact->save();
            $callSave                    = $call->save();
            ContactBlockHistory::log($contact->ID_CONTACT, $call->ID_CALL, $contact->FID_BLOCK);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            return [
                'contact' => $contactSave,
                'phone' => !$phone->hasErrors(),
                'call' => $callSave,
                'contactError' => $contact->getFirstErrors()
            ];
        }
    }
    /**
     * Метод для установки перезвона
     * @todo сделать form отдельный для данного экшена
     */
    public function actionSaveInquirie()
    {

        if (Yii::$app->request->isAjax) {

            $inquirie  = Inquirie::loadFromForm();

            $callForm  = Call::loadFromForm(); /* new Call(Yii::$app->request->post()) */;

            $call    = Call::findOne(['ID_CALL' => $callForm->ID_CALL]);
            $contact = Contact::findOne(['ID_CONTACT' => $call->FID_CONTACT]);
            ContactBlockHistory::log($contact->ID_CONTACT, $call->ID_CALL, $contact->FID_BLOCK);
            \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
            var_dump($inquirie); die();

            $inquirieSave=$inquirie->save();
        }
        return [
            'inquirieSave' =>$inquirieSave
        ];
    }


    /**
     * Метод для работы в браузере
     * @param integer $scenario
     */
    public function actionScenario($scenario = 101)
    {
        $directions       = ['in', 'out'];
        $projectid        = "project1";
        $session_id       = "test_".rand(1, 999999);
        $caller           = "test_mtsbanktm_dev1";
        $contact          = Contact::findOne(['FID_SCENARIO' => $scenario]);
        $npcDialerContact = $contact->DIALER_EXT_ID;
        $direction        = $directions[rand(0, 1)];
        $phone            = Phones::findOne(['FID_CONTACT' => $contact->ID_CONTACT]);
        $phone            = Yii::$app->params['phonePrefix'].$phone->PHONE_NUMBER;
        $url              = \yii\helpers\BaseUrl::home(true);
        $url .= "?r=call/index&session_id=$session_id&caller=$caller&called=$phone&project=$projectid&direction=$direction&npcp-dialer-client-ext-id=$npcDialerContact";
        header("Location:$url");
        die();
    }
}
