<?php

namespace GTrader;

use Illuminate\Database\Eloquent\Collection;
use GTrader\Chart;
use GTrader\Exchange;
use GTrader\Candle;
use GTrader\Util;

class Series extends Collection {
    use HasParams, HasIndicators;
    
    private $_loaded;
    private $_iter = 0;
    
    
    function __construct(array $params = [])
    {
        foreach (['resolution'    => 'resolution', 
                    'name_sql'    => 'exchange', 
                    'symbol_sql'  => 'symbol'] as 
                    $confname     => $varname)
        {
            $this->setParam($varname, 
                            isset($params[$varname]) ?
                            $params[$varname] :
                            Exchange::make()->getParam($confname));
        }

        $this->setParam('limit', isset($params['limit']) ? $params['limit'] : 200);
        $this->setParam('start', isset($params['start']) ? $params['start'] : 0);
        $this->setParam('end', isset($params['end']) ? intval($params['end']) : time());
        $this->setParam('resolution', intval($this->getParam('resolution')));
        parent::__construct();
    }
    
    
    public function getCandles()
    {
        $this->_load();
        return $this;
    }


    public function setCandles(Series $candles)
    {
        //$this->clean();
        $this->items = $candles->items;
        return $this;
    }
    
    public function byKey($key) 
    {
        $this->_load();
        return isset($this->items[$key]) ? $this->items[$key] : null;
    }
    
    
    public function next($advance_iterator = true)
    {
        $this->_load();
        $ret = isset($this->items[$this->_iter]) ? $this->items[$this->_iter] : null;
        if ($advance_iterator) $this->_iter++;
        return $ret;
    }
    
    
    public function prev($stepback = 1, $redvance_iterator = false) 
    {
        $this->_load();
        $ret = isset($this->items[$this->_iter-$stepback-1]) ?
                  $this->items[$this->_iter-$stepback-1] :
                  null;
        if ($redvance_iterator) $this->_iter -= $stepback+1;
        return $ret;
    }
    
    
    public function set($candle = null) 
    {
        if (!is_object($candle)) throw new \Exception('set needs candle object');
        $this->_load();
        if (isset($this->items[$this->_iter-1]))
        {
            $this->items[$this->_iter-1] = $candle;
            return true;
        }
        return false;
    }
    

    
    
    public function all() 
    {
        $this->_load();
        return $this->items;
    }
    
    
    public function size() 
    {
        $this->_load();
        return count($this->items);
    }
    
    
    function add($candle) 
    {
        $this->items[] = $candle;
    }
    
    
    function reset() 
    {
        $this->_iter = 0;
        return $this;
    }
    
    
    function clean() 
    {
        $this->items = array();
        $this->_loaded = false;
        $this->reset();
    }
    
    
    private function _load() 
    {
        if ($this->_loaded) return false;
        $this->_loaded = true;
        
        $start = $this->getParam('start');
        if ($start < 0) $start = 0;
        $end = $this->getParam('end') ? $this->getParam('end') : time();
        $limit = $this->getParam('limit');
        $no_limit = $limit < 1 ? true : false;
                
        if (count($this->items)) return;
        
        $candles = Candle::select('time', 'open', 'high', 'low', 'close', 'volume')
                        ->where('resolution', strval($this->getParam('resolution')))
                        ->where('symbol', $this->getParam('symbol'))
                        ->where('exchange', $this->getParam('exchange'))
                        ->where('time', '>=', $start)
                        ->where('time', '<=', $end)
                        ->orderBy('time', 'desc')
                        ->when(!$no_limit, function ($query) use ($limit) {
                            return $query->limit($limit);
                        })
                        ->get()
                        ->reverse()
                        ->values();

        if ($candles->isEmpty()) throw new \Exception('Empty result');

        $this->items = $candles->items;
    }
    
    
    public function save() 
    {
        $this->reset();
        while ($candle = $this->next())
            $candle->save();
        return true;
    }
    
    
    public function getStartOnly($resolution = null, $symbol = null) 
    {
        if (is_null($resolution)) 
            $resolution = $this->getParam('resolution') ? 
                        $this->getParam('resolution') : 
                        \Config::get('exchange.default_resolution');
        if (is_null($symbol)) 
            $symbol = $this->getParam('symbol') ? 
                        $this->getParam('symbol') : 
                        \Config::get('exchange.symbol_sql');
        
        $c = Candle::select('time')
                    ->where('symbol', $symbol)
                    ->where('resolution', $resolution)
                    ->orderBy('time')
                    ->first();
        return isset($c->time) ? $c->time : null;
    }
    


    
    
    /** Simple Moving Average */
    public function sma($len = 0, $price = 'close') 
    {
        $len = intval($len);
        if ($len <= 1) throw new \Exception('sma needs int len > 1');
        if (!in_array($price, array('open', 'high', 'low', 'close'))) 
            throw new \Exception('sma needs valid price');
        
        $indicator = 'sma_'.$len.'_'.$price;
        if (isset($this->_indicators[$indicator]))
          return $this;
        $this->_indicators[$indicator] = true;
        
        $this->reset();
        while ($candle = $this->next()) {
          //echo 'candle: '; dump($candle);
          $total = 0;
          for ($i=1; $i<=$len; $i++) {
            $prev_candle = $this->prev($i);
            //echo 'prev candle: '; dump($prev_candle);
            if (is_object($prev_candle))
              $total += $prev_candle->$price;
          }
          if ($total)
            $this->items[$this->_iter-1]->$indicator = $total/$len;
        }
        //dump($this->_candles);
        return $this;
    }
    
    

    
    
    /** Relative Strength Index */
    public function rsi($len = 0, $price = 'close')
    {
        $len = intval($len);
        if ($len <= 1) 
            throw new \Exception('rsi needs int len > 1');
        if (!in_array($price, array('open', 'high', 'low', 'close'))) 
            throw new \Exception('rsi needs valid price');
        
        $indicator = 'rsi_'.$len.'_'.$price;
        if (isset($this->_indicators[$indicator])) 
            return $this;
        $this->_indicators[$indicator] = true;
        
        $this->reset();
        
        // forward $len, add up gain/loss, set rsi to 50
        $sum_gain = $sum_loss = 0;
        $prev_candle = null;
        for ($i=0; $i<$len; $i++) 
        {
            if (!($candle = $this->next())) 
                throw new \Exception('series is shorter than '.$len);
            if ($prev_candle) 
            {
                $diff = $candle->$price - $prev_candle->$price;
                if ($diff > 0) $sum_gain += $diff;
                else if ($diff < 0) $sum_loss -= $diff;
            }
            $candle->$indicator = 50;
            $this->set($candle);
            $prev_candle = $candle;
        }
    
        // first rsi value is based on avg prev $len gain/loss
        $prev_avg_gain = $sum_gain / $len;
        $prev_avg_loss = $sum_loss / $len;
        $divider = $prev_avg_gain / $prev_avg_loss;
        if ($divider == -1) $divider = 0;
        $candle->$indicator = 100 - (100 / (1 + $divider));
        $this->set($candle);
        
        // subsequent rsi values are based on prev avgs and current gain/loss
        while ($candle = $this->next()) {
            $diff = $candle->$price - $prev_candle->$price;
            $current_gain = $current_loss = $avg_gain = $avg_loss = 0;
            if ($diff > 0) $current_gain = $diff;
            else if ($diff < 0) $current_loss = -1 * $diff;
            $avg_gain = ($prev_avg_gain * ($len - 1) + $current_gain) / $len;
            $avg_loss = ($prev_avg_loss * ($len - 1) + $current_loss) / $len;
            $divider = $avg_gain / $avg_loss;
            if ($divider == -1) $divider = 0;
            $candle->$indicator = 100 - (100 / (1 + $divider));
            $this->set($candle);
            $prev_candle = $candle;
            $prev_avg_gain = $avg_gain;
            $prev_avg_loss = $avg_loss;
        }
        
        return $this;
    }
    
    
    /** Deviation Squared */
    public function dev_sq($smalen = 20, $price = 'close') 
    {
        $smalen = intval($smalen);
        if ($smalen <= 1) 
            throw new \Exception('need int smalen > 1');
        if (!in_array($price, array('open', 'high', 'low', 'close'))) 
            throw new \Exception('need valid price');
        
        $indicator = 'dev_sq_'.$smalen.'_'.$price;
        if (isset($this->_indicators[$indicator]))
          return $this;
        $this->_indicators[$indicator] = true;
        
        $series = $this->sma($smalen, $price);
        $indicator_sma = 'sma_'.$smalen.'_'.$price;
        $series->reset();
        
        while ($candle = $series->next()) 
        {
            $sma = isset($candle->$indicator_sma) ? $candle->$indicator_sma : $candle[$price];
            $candle->$indicator = pow($candle->$price - $sma, 2);
            //error_log('dev_sq = '.$candle[$indicator]);
            $series->set($candle);
        }
        return $series;
    }
    
    
    /** Midrate */
    public static function ohlc4(Candle $candle)
    {
        if (isset($candle->open) && isset($candle->high) && isset($candle->low) && isset($candle->close))
            return ($candle->open + $candle->high + $candle->low + $candle->close) / 4;
        else throw new \Exception('Candle component missing');
    }
    
    
    /** Bollinger Bands */
    function bb($len = 20, $stdev = 2, $price = 'close')
    {
        $len = intval($len);
        if ($len <= 1) throw new \Exception('bb needs int len > 1');
        $stdev = floatval($stdev);
        if ($stdev < 1) throw new \Exception('bb needs float stdev >= 1');
        if (!in_array($price, array('open', 'high', 'low', 'close'))) throw new \Exception('bb needs valid price');
        
        $indicator1 = 'bb_high_'.$len.'_'.$stdev.'_'.$price;
        $indicator2 = 'bb_low_'.$len.'_'.$stdev.'_'.$price;
        if (isset($this->_indicators[$indicator1]) && isset($this->_indicators[$indicator2])) return $this;
        $this->_indicators[$indicator1] = $this->_indicators[$indicator2] = true;
        
        $series = $this->dev_sq($len, $price);
        $indicator_sma = 'sma_'.$len.'_'.$price;
        $indicator_dev_sq = 'dev_sq_'.$len.'_'.$price;
        $series->reset();
        
        $dev_sq_arr = array();
        while ($candle = $series->next()) 
        {
            array_push($dev_sq_arr, $candle->$indicator_dev_sq);
            if (count($dev_sq_arr) > $len) array_shift($dev_sq_arr);
            $st_dev = sqrt(array_sum($dev_sq_arr) / $len);
            $sma = isset($candle->$indicator_sma) ? $candle->$indicator_sma : $candle->$price;
            $candle->$indicator1 = $sma + $st_dev;
            $candle->$indicator2 = $sma - $st_dev;
            $series->set($candle);
        }
        return $series;
    }
    
    
    // Series::crossover($prev_candle, $candle, 'rsi_4_close', 50)
    // Series::crossunder($prev_candle, $candle, 'close', 'bb_low_58_2_close')
    public static function crossover($prev_candle, $candle, $fish, $sea, $direction = 'over') {
    
    if (is_numeric($fish)) $fish1 = $fish2 = $fish + 0;
    else if (is_string($fish))
    {
      if (isset($prev_candle->$fish)) $fish1 = $prev_candle->$fish;
      else throw new \Exception('Could not find fish1');
      if (isset($candle->$fish)) $fish2 = $candle->$fish;
      else throw new \Exception('Could not find fish2');
    }
    else throw new \Exception('Fish must either be string or numeric');
    
    if (is_numeric($sea)) $sea1 = $sea2 = $sea+0;
    else if (is_string($sea)) 
    {
      if (isset($prev_candle->$sea)) $sea1 = $prev_candle->$sea;
      else throw new \Exception('Could not find sea1');
      if (isset($candle->$sea)) $sea2 = $candle->$sea;
      else throw new \Exception('Could not find sea2');
    }
    else throw new \Exception('Sea must either be string or numeric');
    
    return $direction == 'under' ?
      $fish1 > $sea1 && $fish2 < $sea2:
      $fish1 < $sea1 && $fish2 > $sea2;
    }
    
    
    public static function crossunder($prev_candle, $candle, $fish, $sea) 
    {
        return self::crossover($prev_candle, $candle, $fish, $sea, 'under');
    }
    
    
    public static function normalize($in, $in_min, $in_max, $out_min = -1, $out_max = 1) 
    {
        if ($in_max - $in_min == 0) return $out_max - $out_min;
        //throw new \Exception('Division by zero: '.$in.' '.$in_min.'-'.$in_max.' '.$out_min.'-'.$out_max);
        return ($out_max - $out_min) / ($in_max - $in_min) * ($in - $in_max) + $out_max;
    }
}



?>