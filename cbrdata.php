<?php

namespace models;

use config\db;

/**
 * Модель CBRData
 *
 * @author Lexing
 */
class Cbrdata {
    /**
    * URL XML файла для парсинга
    * 
    * @var string 
    */
    private $url = 'http://www.cbr.ru/scripts/XML_daily.asp?date_req=';
    
    /**
    * Получает через CURL содержимое XML файла
    * 
    * @return string 
    */
    public function get_file( $url  ){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, 0); // читать заголовок
        curl_setopt($ch, CURLOPT_NOBODY, 0); // читать ТОЛЬКО заголовок без тела
        curl_setopt ($ch, CURLOPT_USERAGENT, "Opera/9.80 (Windows NT 5.1; U; ru) Presto/2.10.289 Version/12.01"); 
        # User-Agent

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
        # Убираем вывод данных в браузер. Пусть функция их возвращает а не выводит
        
        $result = curl_exec($ch);  
        if( $result === false){
            print_r( 'Ошибка: ' . curl_error($ch) .' | '. curl_errno($ch) );
        }
        curl_close($ch);

        $result = mb_convert_encoding($result, "utf-8", "windows-1251");

        $result = str_replace('encoding="windows-1251"', 'encoding="utf-8"', $result);
        
        
        return $result;
    }
    
    /** Возвращает данные по валютам за последние $count_days дней
     * 
     * @param int $count_days количество дней
     * @return array массив данных курса валют за указанное количество дней
     */
    public function get_data_from_xml( $count_days ){
        $data = array();
        // длина суток в секундах
        $day_sec = 24*3600;
        $current_time = mktime(0, 0, 0, date('m'), date('d'), date('Y') );
        for($i=0; $i<$count_days; ++$i){
            $time = $current_time - $day_sec * $i;
            $xml_string = $this->get_file( $this->url . date('d/m/Y', $time) );
            $data[] = $this->parse_xml($xml_string, $time);
        }
        return $data;
    }
    
    /** 
     * Обрабатывает курсы валют с сайта ЦБ РФ за последние $count_days количество дней
     * @param int $count_days количество дней
     */
    public function processing( $count_days ){
        $data =  $this->get_data_from_xml($count_days);
        
        for($i=0; $i<$count_days; ++$i){
            DB::multiinsert('currency', $data[$i]);
        }
    }

    /**
     * Очистка таблицы currency
     */
    public function clear_table(){
        $db = DB::getInstance();
        $statement = $db->query('TRUNCATE TABLE currency');
    }

    
    /** Парсит XML строку
     * 
     * @param string $xml_string XML файл строкой
     * @param string $date_unix дата в Unix формате
     * @return array Данные по валютам в виде массива
     */
    public function parse_xml( $xml_string, $date_unix ){
        $data = array();
        $xml =  simplexml_load_string($xml_string, NULL, LIBXML_NOCDATA|LIBXML_COMPACT|LIBXML_NOWARNING);
        if ($xml) {
            if ( !empty($xml->Valute) ){

                $size = count($xml->Valute);
                for($i=0; $i<$size; ++$i){
                    $valute = $xml->Valute[$i];
                    $data[] = array(
                        'valuteID' => (string)$xml->Valute[$i]['ID'],
                        'numCode' => (string)$xml->Valute[$i]->NumCode,
                        'сharCode' => (string)$xml->Valute[$i]->CharCode,
                        'name' => (string)$xml->Valute[$i]->Name,
                        'value' =>  str_replace(',', '.', (string)$xml->Valute[$i]->Value),
                        'date' => $date_unix,
                    );
                }
            }
        }
        return $data;
    }

    /**
     * Возвращает курсы валют на $day
     * @param date $day Дата в формате UNIX
     * @return array массив курсов валют на заданую дату
     */
    public function get_data( $day ){
        $data = array();
        $db = DB::getInstance();
        $statement = $db->prepare('SELECT valuteID, numCode, сharCode, name, value, date
                                    FROM currency
                                    WHERE date = :date
                                    ORDER BY сharCode ASC');
        $statement->execute(array(
            'date' => intval($day)
        ));
        while( $res = $statement->fetch() ){
            $data[] = $res;
        }
        return $data;
    }
    
    /**
     * Возвращает даты, которые есть в таблице currency
     * @return array массив дат
     */
    public function get_dates(  ){
        $data = array();
        $db = DB::getInstance();
        $statement = $db->prepare('SELECT date as date_unix
                                    FROM currency
                                    GROUP BY date
                                    ORDER BY date DESC');
        $statement->execute();
        $i = 0;
        while( $res = $statement->fetch() ){
            $data[$i] = $res;
            $data[$i]['date'] = date('d/m/Y', $data[$i]['date_unix']);
            ++$i;
        }
        return $data;
    }

    /** Возвращает курсы валюты $value_ID на заданный период времени
     * 
     * @param string $value_ID ID валюты
     * @param date $date_from дата от
     * @param date $date_to дата до
     * @return array массив курсов валюты
     */
    public function get_currency($value_ID, $date_from, $date_to){
        $d = explode('/', $date_from);
        $date_unix_from = mktime(0, 0, 0, $d[1], $d[0], $d[2] );
        
        $d = explode('/', $date_to);
        $date_unix_to = mktime(0, 0, 0, $d[1], $d[0], $d[2] );
        
        $data = array();
        
        $db = DB::getInstance();
        $statement = $db->prepare('SELECT valuteID, numCode, сharCode, name, value, date
                                FROM currency
                                WHERE
                                valuteID = :valuteID
                                AND date >= :date_unix_from
                                AND date <= :date_unix_to
                                ORDER BY date DESC');
        $statement->execute(array(
            'valuteID' => $value_ID,
            'date_unix_from' => $date_unix_from,
            'date_unix_to' => $date_unix_to,
        ));
        while( $res = $statement->fetch() ){
            $data[] = $res;
        }
        
        return $data;        
    }
}
