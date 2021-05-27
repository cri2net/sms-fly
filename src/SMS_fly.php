<?php

namespace cri2net\sms_fly;

use Exception;
use cri2net\sms_client\AbstractSMS;
use cri2net\php_pdo_db\PDO_DB;

class SMS_fly extends AbstractSMS
{
    /**
     * Ссылка на API
     * @since 1.0.0
     */
    const API_URL             = 'https://sms-fly.com/api/api.php';
    const API_URL_NO_ALFANAME = 'https://sms-fly.com/api/api.noai.php';

    /**
     * Альфаимя отправителя sms, доступное по умолчанию всем клиентам sms-fly
     * Установите значение в false, чтобы отправлять без альфаимени (дешевле)
     * @var string|false
     * @since 1.0.1
     */
    public $alfaname = 'InfoCenter';

    /**
     * Конструктор
     * @param string $login    Логин для доступа к API
     * @param string $password Пароль для доступа к API
     */
    public function __construct($login = null, $password = null)
    {
        if ($login != null) {
            $this->login = $login;
        }
        if ($password != null) {
            $this->password = $password;
        }
    }

    /**
     * Возвращает ключ текущего шлюза для хранения в БД привязки sms к шлюзу
     * @return string Ключ текущего шлюза
     */
    public function getProcessingKey()
    {
        return 'sms_fly';
    }

    /**
     * Возвращает ссылку для обращений к API
     * @return string ссылка для работы с API
     */
    public function getApiUrl()
    {
        if ($this->alfaname === false) {
            return self::API_URL_NO_ALFANAME;
        }
        return self::API_URL;
    }

    /**
     * метод для проверки остатка на балансе в аккаунте на sms-fly
     * @return double кол-во гривен
     */
    public function getBalance()
    {
        $response = $this->sendPOST('GETBALANCE');
        return floatval($response->balance . '');
    }

    /**
     * Отправка запроса на API
     * @param  string $operation метод на стороне API, который будет вызван
     * @param  string $data      Строка с XML телом основной части передаваего запроса к API
     * @return SimpleXML object  ответ от API
     */
    protected function sendPOST($operation, $data = '')
    {
        if (!extension_loaded('curl')) {
            throw new Exception('cURL extension missing');
        }

        $data = '<?xml version="1.0" encoding="utf-8"?><request>'
              . '<operation>' . $operation . '</operation>'
              . $data . '</request>';

        $ch = curl_init($this->getApiUrl());
        $options = [
            CURLOPT_USERPWD        => $this->login . ':' . $this->password,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST           => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: text/xml",
                "Accept: text/xml",
            ],
        ];

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception($error);
        }
        curl_close($ch);

        $response = @simplexml_load_string($response);
        if (($response === false) || ($response === null)) {
            throw new Exception('String could not be parsed as XML');
        }

        return $response;
    }

    /**
     * Проверка статуса sms
     * @param  integer $campaignID ID кампании рассылки в системе sms-fly
     * @param  string  $recipient  номер получателя
     * @return string              Текущий статус
     */
    public function checkStatus($campaignID, $recipient)
    {
        $recipient = $this->processPhone($recipient);
        $response = $this->sendPOST('GETMESSAGESTATUS', '<message campaignID="' . $campaignID . '" recipient="' . $recipient . '" />');
        return $response->state->attributes()->status . '';
    }
    
    /**
     * Реализация отправки SMS
     * @param  string $to          телефон получателя в международном формате
     * @param  string $text        текст сообщения
     * @param  string $description описание кампании в веб интерфейсе sms-fly
     * @return array               Детали об отправке
     */
    public function sendSMS($to, $text, $description = '')
    {
        $to = $this->processPhone($to);
        $text = htmlspecialchars($text, ENT_QUOTES);
        $description = htmlspecialchars($description, ENT_QUOTES);
        $source = ($this->alfaname === false) ? '' : 'source="' . $this->alfaname . '"';
        
        $data = '<message start_time="AUTO" end_time="AUTO" lifetime="24" rate="120" desc="' . $description . '" ' . $source . '>';
        $data .= "<body>" . $text . "</body>";
        $data .= "<recipient>" . $to . "</recipient>";
        $data .= "</message>";

        $response = $this->sendPOST('SENDSMS', $data);
        $code = $response->state->attributes()->code . '';
        
        switch ($code) {

            case 'ACCEPT':
                return [
                    'campaignID' => $response->state->attributes()->campaignID . '',
                    'status'     => $response->to[0]->attributes()->status . '',
                ];

            default:
                throw new Exception(self::getErrorText($code));
        }
    }

    /**
     * sms-fly работает с номерами без символа + в начале номера
     * 
     * @param  string $international_phone Номер телефона в международном формате
     * @return string                      Преобразованный номер
     */
    public function processPhone($international_phone)
    {
        return str_replace('+', '', $international_phone);
    }

    /**
     * Метод проверяет статусы всех сообщений, которые находятся в незавершённом состоянии.
     * Предназначен для вызова из крона
     * @return void
     */
    public function checkStatusByCron()
    {
        if (empty($this->table)) {
            throw new Exception("Поле table не задано");
        }

        $stm = PDO_DB::prepare("SELECT * FROM {$this->table} WHERE processing=? AND status IN ('complete') AND (processing_status IS NULL OR processing_status IN ('ACCEPTED', 'PENDING', 'SENT'))");
        $stm->execute([$this->getProcessingKey()]);

        while ($item = $stm->fetch()) {

            $update = [];

            try {
                $processing_data = json_decode($item['processing_data']);
                $status = $this->checkStatus($processing_data->first->campaignID, $item['to']);
                // Описание статуса можно получить через self::getStateText($status);

                $update['updated_at'] = microtime(true);
                $update['processing_status'] = $status;

            } catch (Exception $e) {
            }

            PDO_DB::update($update, $this->table, $item['id']);
        }
    }
    
    /**
     * Метод отдаёт текстовое описание кода ошибки от шлюза
     * @param  string $code код ошибки
     * @return string       Описание ошибки
     */
    public static function getErrorText($code)
    {
        switch ($code) {
            case 'XMLERROR':     return 'Некорректный XML';
            case 'ERRPHONES':    return 'Неверно задан номер получателя';
            case 'ERRSTARTTIME': return 'Не корректное время начала отправки';
            case 'ERRENDTIME':   return 'Не корректное время окончания рассылки';
            case 'ERRLIFETIME':  return 'Не корректное время жизни сообщения';
            case 'ERRSPEED':     return 'Не корректная скорость отправки сообщений';
            case 'ERRALFANAME':  return 'Данное альфанумерическое имя использовать запрещено, либо ошибка';
            case 'ERRTEXT':      return 'Некорректный текст сообщения';

            default:             return 'Неизвестный код ошибки ' . $code;
        }
    }
    
    /**
     * Метод отдаёт текстовое описание кода состояния сообщения
     * @param  string $code Статус от шлюза
     * @return string       Описание статуса
     */
    public static function getStateText($code)
    {
        switch ($code) {
            case 'DELIVERED':       return 'Доставлено';
            case 'EXPIRED':         return 'Срок жизни сообщения истёк, сообщения не доставлено получателю.';
            case 'UNDELIV':         return 'Сообщение не может быть доставлено. Возможно, номер абонента не верен, либо абонент отключен.';
            case 'ALFANAMELIMITED': return 'Сообщение не может быть отправлено абоненту этого оператора, так как Альфаимя ограничено.';
            case 'STOPED':          return 'Сообщение остановлено системой. Проверьте баланс.';
            case 'USERSTOPED':      return 'Сообщение остановлено пользователем через WEB интерфейс.';
            case 'ERROR':           return 'Системная ошибка при отправке сообщения.';
            case 'PENDING':         return 'Сообщение в очереди на отправку.';
            case 'SENT':            return 'Сообщение отправлено абоненту. Ожидается статус сообщения от оператора.';

            default:                return 'Неизвестный код состояния ' . $code;
        }
    }
}
