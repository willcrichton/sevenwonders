<?php

class Player {

    private $id;
    private $name;
    private $conn;

    public $game;
    private $coins = 0;
    private $permResources = array();
    private $tempResources = array();
    private $wonder;
    private $order;
    private $hand;
    private $selectedCard;
    private $cardsPlayed = array();
    private $military = 0;
    private $militaryPoints = array(0,0,0,0,0,0);
    private $points = 0;
    private $science = array(0, 0, 0, 0);
    private $isTrashing = false;
    private $isBuildWonder = false;
    private $leftPlayer;
    private $rightPlayer;
    private $discounts = array('left' => array(), 'right' => array());

    public function __construct($id, $unique) {
        $this->name = "Guest $unique";
        $this->id = $id;
    }

    public function getId() {
        return $this->id;
    }

    public function getName() {
        return $this->name;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function setConnection(IWebSocketConnection $conn) {
        $this->conn = $conn;
    }

    public function send($type, $msg) {
        if ($this->conn == null)
            throw new Exception("user not connected any more");
        $msg = is_array($msg) ? $msg : array('data' => $msg);
        $msg['messageType'] = $type;
        $this->conn->sendString(json_encode($msg));
    }

    public function checkResourceCost($cost, $resources){
        $allZero = true;
        foreach($cost as $resource => $amount){
            if($amount > 0){
                $allZero = false;
                break;
            }
        }
        if($allZero) return true;
        if(count($resources) == 0) return false;

        $resource = array_pop($resources);
        if(is_array($resource->resources)){
            foreach($resource->resources as $possibility){
                if(!isset($cost[$possibility])) continue;
                $cost[$possibility]--;
                if($this->checkResourceCost($cost, $resources)) return true;
                $cost[$possibility]++;
            }
            return $this->checkResourceCost($cost, $resources);
        } else {
            $possibility = $resource->resources;
            if(isset($cost[$possibility])){
                $cost[$resource->resources]--;
                if($this->checkResourceCost($cost, $resources)) return true;
                $cost[$possibility]++;
            } else {
                return $this->checkResourceCost($cost, $resources);
            }
        }

        return false;
    }

    public function canPlayCard(WonderCard $card, $sendError = false){
        // check for duplicates
        foreach($this->cardsPlayed as $cardPlayed){
            if($cardPlayed->getName() == $card->getName()){
                if($sendError) $this->sendError("You've already played this card");
                return false;
            }
        }

        // check if it's a prerequisite for being free
        foreach($this->cardsPlayed as $cardPlayed)
            if($cardPlayed->getName() == $card->getPrereq()) return true;

        // check if player has enough money
        if($card->getMoneyCost() > $this->coins){
            if($sendError) $this->sendError("You don't have enough coins to play this card");
            return false;
        }

        // check if player has necessary resources
        $cost = $card->getResourceCost();
        $availableResources = array_merge($this->permResources, $this->tempResources);
        if(!$this->checkResourceCost($cost, $availableResources)){
            if($sendError) $this->sendError("You don't have enough resources to play this card");
            return false;
        }

        return true;
    }

    public function canSellResource($resource){
        if(!isset($this->permResources[$resource])) return false;
        return isset($this->permResources[$resource]['buy']);
    }

    public function sendError($error){
        $packet = packet($error, 'error');
        $this->sendString($packet);
    }

    public function addCoins($coins){
        $this->coins += $coins;
        $this->sendString(packet($this->coins, 'coins'));
    }

    public function addResource($resources, $amount, $buyable = true){
        for($i = 0; $i < $amount; $i++){
            $resource = new Resource();
            $resource->buyable = $buyable;
            $resource->resources = $resources;
            $this->permResources[] = $resource;
            $this->sendString(packet(array('resources' => $this->permResources), 'resources'));
        }
    }

    public function addScience($element){
        if(!isset($this->science[$element]))
            $this->science[$element] = 1;
        else
            $this->science[$element]++;
    }

    public function evaluateMilitary($age){
        if($this->leftPlayer->military < $this->military){
            $this->militaryPoints[$age * 2 - 1] += 1;
        } elseif($this->leftPlayer->military > $this->military){
            $this->militaryPoints[0] += 1;
        }

        if($this->rightPlayer->military < $this->military){
            $this->militaryPoints[$age * 2 - 1] += 1;
        } elseif($this->rightPlayer->military > $this->military){
            $this->militaryPoints[0] += 1;
        }

        $this->sendString(packet($this->militaryPoints, 'military'));
    }

    public function calcScience($science, $wildcards){
        if($wildcards > 0){
            $possibles = array();
            for($i = 1; $i <= 3; $i++){
                $science[$i - 1]--; $science[$i]++;
                array_push($possibles, $this->calcScience($science, $wildcards - 1));
            }
            return max($possibles);
        } else {
            $total = 0;
            for($i = 1; $i <= 3; $i++)
                $total += pow($science[$i], 2);
            if($total == 0) return 0;
            $total += min(array_slice($science, 1)) * 7;
            return $total;
        }
    }

    public function calcPoints(){
        // blue cards
        $total = $this->points;

        // coins
        $total += floor($this->coins / 3);

        // military
        foreach($this->militaryPoints as $mult => $tokens)
            $total += ($mult == 0 ? -1 : $mult) * $tokens;

        // science
        $total += $this->calcScience($this->science, $this->science[0]);

        // 3rd age yellow cards + guild cards
        foreach($this->cardsPlayed as $card){
            if($card->getAge() == 3){
                if($card->getColor() == 'yellow'){
                    preg_match('/\((.)\)\{(.)\} (.+)?/', $card->getCommand(), $matches);
                    $mult = $matches[2]; $color = $matches[3];
                    $sum = 0;
                    if($color == 'wonder'){
                        // check for wonders here
                    } else {
                        foreach($this->cardsPlayed as $c){
                            if($c->getColor() == $color) $sum++;
                        }
                    }
                    $total += intval($mult) * $sum;
                } elseif($card->getColor() == 'purple' && $card->getName() != "Scientists Guild"){
                    $args = explode(' ', $card->getCommand());
                    $directions = arrowsToDirection($args[0]);
                    $color = $args[1];
                    $mult = intval($args[2]);
                    /************* THIS NEEDS TO BE TESTED ****************/
                    foreach($directions as $dir){
                        $pl = $dir == 'left' ? $this->leftPlayer : ($dir == 'right' ? $this->rightPlayer : $this);
                        switch($color){
                            case '-1':
                                $total += $mult * (isset($pl->militaryPoints[0]) ? $pl->militaryPoints[0] : 0);
                                break;
                            case 'wonder':
                                // check for wonder here
                                break;
                            case 'brown,grey,blue':
                                foreach(explode(',', $color) as $subcolor){
                                    foreach($pl->cardsPlayed as $c){
                                        if($c->getColor() == $subcolor) $total += $mult;
                                    }
                                }
                                break;
                            default:
                                foreach($pl->cardsPlayed as $c){
                                    if($c->getColor() == $color) $total += $mult;
                                }
                                break;
                        }
                    }
                }
            }
        }

        return $total;
    }

}
