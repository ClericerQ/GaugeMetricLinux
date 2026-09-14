<?php

class cDate {
    private $date;

    public function datestring($date, $format): string|false {
        try {
            $this->date = new DateTime($date);
        } catch (Exception $e) {
            return false;
        }

        $phpFormat = str_replace(
            ['YYYY', 'dd', 'mm', 'hh', 'ii', 'ss'],
            ['Y',    'd',  'm',  'H',  'i',  's'],
            $format
        );

        return $this->date->format($phpFormat);
    }
}
