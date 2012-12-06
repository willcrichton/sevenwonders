<?php

class Resource {
    const STONE = 0;
    const WOOD  = 1;
    const ORE   = 2;
    const CLAY  = 3;
    const LINEN = 4;
    const GLASS = 5;
    const PAPER = 6;

    private $amts = array(self::STONE => 0, self::WOOD => 0, self::ORE => 0,
                          self::CLAY => 0, self::LINEN => 0, self::GLASS => 0,
                          self::PAPER => 0);
    private $only_one = false;
    private $_buyable = false;

    public function __construct($only_one, $buyable) {
        $this->only_one = $only_one;
        $this->_buyable = $buyable;
    }

    public function buyable() {
        return $this->_buyable;
    }

    public function add($resource) {
        $this->amts[$resource]++;
    }

    public function discount($discounts) {
        // This isn't exactly optimal, but what we're doing here is that if a
        // discounted resource appears at least once in our resource, then our
        // entire resource is discounted. This turns out to always be true
        // because discounts apply to WOCS or LGP, and no card has both of those
        // types of resources (thankfully)
        foreach ($discounts as $resource)
            foreach ($resource->amts as $type => $amt)
                if ($amt > 0 && $this->amts[$type] > 0)
                    return 1;
        return 2;
    }

    public static function one($res) {
        $ret = new Resource(false, false);
        $ret->add($res);
        return $ret;
    }

    public static function satisfiable($want, $have) {
        $have = array_map(function($r) { return ResourceOption::me($r); },
                          $have);

        // print_r($want);
        // echo "\n";
        // print_r($have);
        // echo "\n";
        $ret = self::satisfy($want, $have);
        // print_r($ret);
        // echo "\n";
        foreach ($ret as $dir) {
            $good = true;
            foreach ($dir as $amt) {
                if ($amt > 0) {
                    $good = false;
                    break;
                }
            }
            if ($good)
                return true;
        }
        return false;
    }

    public static function satisfy($want, $have) {
        $total = array(self::STONE => 0, self::WOOD => 0, self::ORE => 0,
                       self::CLAY => 0, self::LINEN => 0, self::GLASS => 0,
                       self::PAPER => 0);
        foreach ($want as $resource) {
            foreach ($resource->amts as $res => $amt) {
                $total[$res] += $amt;
            }
        }

        $ret = array();
        self::tryuse(array('left' => 0, 'right' => 0, 'self' => 0),
                     $have, $total, $ret);
        return $ret;
    }

    private static function tryuse($costs, $available, &$want, &$ret) {
        $allZero = true;
        foreach ($want as $amount) {
            if ($amount > 0) {
                $allZero = false;
                break;
            }
        }
        if ($allZero) {
            $ret[] = $costs;
            return;
        }
        if (count($available) == 0)
            return;

        $option = array_pop($available);
        $resource = $option->resource;
        $costs[$option->direction] += $option->cost;

        if ($resource->only_one) {
            // If we can only use one of these resources, try each one
            // individually and see if we can satisfy
            foreach ($resource->amts as $type => $amt) {
                if ($want[$type] <= 0)
                    continue;
                $want[$type] -= $amt;
                self::tryuse($costs, $available, $want, $ret);
                $want[$type] += $amt;
            }
        } else {
            // If we can use this multi-resource, then use as much of it as
            // possible and then move on to using another resource.
            foreach ($resource->amts as $type => $amt)
                $want[$type] -= $amt;
            self::tryuse($costs, $available, $want, $ret);
            foreach ($resource->amts as $type => $amt)
                $want[$type] += $amt;
        }

        $costs[$option->direction] -= $option->cost;

        // Try not using this resource
        self::tryuse($costs, $available, $want, $ret);
    }
}

class ResourceOption {
    public $resource;
    public $direction;
    public $cost;

    public static function left(Resource $resource, $cost = 1) {
        $ret = new ResourceOption();
        $ret->cost = $cost;
        $ret->direction = 'left';
        $ret->resource = $resource;
        return $ret;
    }

    public static function right(Resource $resource, $cost = 1) {
        $ret = new ResourceOption();
        $ret->cost = $cost;
        $ret->direction = 'right';
        $ret->resource = $resource;
        return $ret;
    }

    public static function me(Resource $resource) {
        $ret = new ResourceOption();
        $ret->cost = 0;
        $ret->direction = 'self';
        $ret->resource = $resource;
        return $ret;
    }
}

class Science {
    const ANY     = 0;
    const GEAR    = 1;
    const COMPASS = 2;
    const TABLET  = 3;

    private $amts = array(self::ANY => 0, self::GEAR => 0, self::COMPASS => 0,
                          self::TABLET => 0);

    public function add($science) {
        $this->amts[$science]++;
    }

    public function points() {
        if ($this->amts[self::ANY] > 0) {
            $max = 0;
            $this->amts[self::ANY]--;
            foreach (array(self::GEAR, self::COMPASS, self::TABLET) as $s) {
                $this->amts[$s]++;
                $max = max($max, $this->points());
                $this->amts[$s]--;
            }
            $this->amts[self::ANY]++;
            return $max;
        }

        $min = 1000;
        $points = 0;
        foreach ($this->amts as $typ => $amt) {
            if ($typ == self::ANY) continue;
            $points += $amt * $amt;
            $min = min($min, $amt);
        }
        return $points + $min * 7;
    }
}

class Military {
    private $_size = 0;
    private $amt = array(-1 => 0, 1 => 0, 3 => 0, 5 => 0);

    public function fight($other, $age) {
        if ($other->_size > $this->_size) {
            $this->amt[-1]++;
        } else if ($other->_size < $this->_size) {
            $this->amt[2 * $age - 1]++;
        }
    }

    public function points() {
        $sum = 0;
        foreach ($this->amt as $mult => $amt) {
            $sum += $mult * $amt;
        }
        return $sum;
    }

    public function add($amt) { $this->_size += $amt; }
    public function json() { return $this->amt; }
    public function size() { return $this->_size; }
    public function losses() { return $this->amt[-1]; }
}
