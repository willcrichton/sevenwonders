<?php

require_once("cards.php");

class SevenWonders {
    public $debug = true;
    public $name;
    public $players = array();
    public $maxplayers;
    public $creator;
    public $id;
    public $deck;
    public $server;
    public $started = false;
    public $age = 1;
    public $turn = 1;
    public $cardsChosen = array();
    public $tradeQueue = array();
    public $wonders;

    public function __construct(){
        global $deck;
        $this->deck = $deck;
        $this->wonders = array(
            array(
                "name" => "Olympia",
                "resource" => Resource::one(Resource::WOOD)
            ),
            array(
                "name" => "Rhodos",
                "resource" => Resource::one(Resource::ORE)
            ),
            array(
                "name" => "Alexandria",
                "resource" => Resource::one(Resource::GLASS)
            ),
            array(
                "name" => "Ephesos",
                "resource" => Resource::one(Resource::PAPER)
            ),
            array(
                "name" => "Halikarnassus",
                "resource" => Resource::one(Resource::LINEN)
            ),
            array(
                "name" => "Gizah",
                "resource" => Resource::one(Resource::STONE)
            ),
            array(
                "name" => "Babylon",
                "resource" => Resource::one(Resource::CLAY)
            )
        );
    }

    public function log($msg){
        if($this->debug)
            echo "Game " . $this->id . " (turn " . $this->turn . ", age " . $this->age . "): $msg\n";
    }

    public function addPlayer(Player $user){
        foreach($this->players as $player){
            $player->send('newplayer', array('id' => $user->getId(),
                                             'name' => $user->getName()));
            $user->send('newplayer', array('id' => $player->getId(),
                                           'name' => $player->getName()));
        }

        if(count($this->players) == 0)
            $this->creator = $user;
        $this->players[] = $user;
        $user->setGame($this);

        if (count($this->players) == $this->maxplayers)
            $this->startGame();
    }

    public function startGame() {
        // tell the players we're starting the game
        $this->started = true;
        $this->server->broadcast('started', array('id' => $this->id));

        // set up the start conditions
        $wonderKeys = array_keys($this->wonders);
        shuffle($wonderKeys);
        foreach($this->players as $player){
            // starting moneyz
            $player->coins = 3;

            // select a wonder
            $wonder = $this->wonders[array_pop($wonderKeys)];
            $player->wonder = $wonder;
            $player->addResource($wonder['resource'], 1);
        }

        // shuffle order of players
        shuffle($this->players);

        $playerInfo = array();
        for($i = 0; $i < count($this->players); $i++){
            $this->players[$i]->leftPlayer = $i == 0 ? $this->players[count($this->players) - 1] : $this->players[$i - 1];
            $this->players[$i]->rightPlayer = $i == (count($this->players) - 1) ? $this->players[0] : $this->players[$i + 1];
            $this->players[$i]->order = $i + 1;
            $playerInfo[] = array(
                'id' => $this->players[$i]->id(),
                'name' => $this->players[$i]->name(),
                'order' => $this->players[$i]->order
            );
        }

        // send start information
        foreach($this->players as $player){
            $startInfo = array(
                "coins" => $player->coins,
                "wonder" => $player->wonder["name"],
                "plinfo" => $playerInfo,
                "resource" => $player->wonder['resource'],
                "neighbors" => array('left' => $player->leftPlayer->id(),
                                     'right' => $player->rightPlayer->id())
            );
            $player->send("startinfo", $startInfo);
        }

        $this->deal();
    }

    public function deal() {
        $this->deck->deal($this->age, $this->players);
        foreach ($this->players as $player)
            $player->sendHand();
    }

    public function removePlayer(Player $user){
        // drop player from game
        unset($this->players[array_search($user, $this->players)]);
    }

    public function broadcast($type, $msg){
        foreach($this->players as $player)
            $player->send($type, $msg);
    }

    public function rotateHands($moveLeft){
        $player = $this->players[0];
        $tempHand = $player->hand;
        do {
            $neighbor = $moveLeft ? $player->rightPlayer : $player->leftPlayer;
            $player->hand = $neighbor == $player ? $tempHand : $neighbor->hand;
            $player->sendHand();
            $player = $neighbor;
        } while($player != $this->players[0]);
    }

    private function playCards() {
        $this->log("All cards found");

        // execute effects of all played cards
        foreach ($this->players as $player) {
            $data = array();
            if (!$player->leftPlayer->isTrashing)
                $data['left'] = $player->leftPlayer->selectedCard->json();
            if (!$player->rightPlayer->isTrashing)
                $data['right'] = $player->rightPlayer->selectedCard->json();
            $player->send('cardschosen', $data);
            $card = $player->selectedCard;
            if ($player->isTrashing) {
                $this->log("{$player->info()} trashing " . $card->getName());
                $player->addCoins(3);
                $player->isTrashing = false;
            //} elseif($player->isWonderBuilding == true){
            } else {
                $this->log("{$player->info()}) playing " . $card->getName());
                $card->play($player);
                $player->cardsPlayed[] = $card;
            }
            unset($player->hand[array_search($card, $player->hand)]);
            $player->tempResources = array();
        }

        foreach ($this->players as $player)
            unset($player->selectedCard);

        foreach($this->tradeQueue as $trade){
            $trade['player']->addCoins($trade['coins']);
        }
        $this->tradeQueue = array();

        $this->cardsChosen = array();
        if ($this->turn == 6) {
            // go into a new age
            $this->log("Ending age {$this->age}");

            $this->log("Evaluating military");
            foreach ($this->players as $player) {
                $player->evaluateMilitary($this->age);
            }

            $this->age++;
            $this->turn = 1;
            $this->deal();
        } else {
            // change hands and start a new turn
            $this->log("Ending turn " . $this->turn);
            $this->turn++;
            $this->rotateHands($this->age != 2);
        }

        foreach($this->players as $player){
            $this->log("{$player->info()} has {$player->calcPoints()} points");
        }
    }

    public function onMessage(Player $user, $args){
        switch($args['messageType']){
            case 'cardplay':
                $cardName = $args['value'][0];
                // match what they sent with a Card object
                foreach ($user->hand as $card) {
                    if($card->getName() == $cardName) $foundCard = $card;
                }
                if (!isset($foundCard)) // don't have the specified card
                    break;
                if ($args['value'][1] == 'trash') {
                    $user->isTrashing = true;
                } else {
                    $err = $user->canPlayCard($foundCard);
                    if ($err != '') {
                        $user->sendError($err);
                        break;
                    }
                    $user->isTrashing = false;
                }
                $this->log("{$user->info()} chose {$foundCard->getName()}");
                $this->cardsChosen[$user->id()] = $foundCard;
                $user->selectedCard = $foundCard;
                $user->send('canplay', '');
                // if everyone's played a card, execute cards and redeal/new turn
                if (count($this->cardsChosen) == count($this->players)) {
                    $this->playCards();
                }
                break;

            case 'cardignore':
                $user->isTrashing = false;
                unset($user->selectedCard);
                unset($this->cardsChosen[$user->id()]);
                break;

            case 'trade':
                $this->log("{$user->info()} is trading");
                $leftTotal = 0;

                $leftFilter = array_filter($user->leftPlayer->permResources, function($var){ return $var->buyable; });
                if(!$user->leftPlayer->checkResourceCost($args['left'], $leftFilter)){
                    $user->sendError("Left player doesn't have those resources");
                    return;
                }
                /* TODO: change this */
                foreach($args['left'] as $resource => $amount){
                    $discount = isset($user->discounts['left'][$resource]) ? $user->discounts['left'][$resource] : 0;
                    $leftTotal += max((2 - $discount) * $amount, 0);
                }

                $rightTotal = 0;
                $rightFilter = array_filter($user->rightPlayer->permResources, function($var){ return $var->buyable; });
                if(!$user->leftPlayer->checkResourceCost($args['right'], $rightFilter	)){
                    $user->sendError("Right player doesn't have those resources");
                    return;
                }
                foreach($args['right'] as $resource => $amount){
                    $discount = isset($user->discounts['right'][$resource]) ? $user->discounts['right'][$resource] : 0;
                    $rightTotal += max((2 - $discount) * $amount, 0);
                }

                $total = $leftTotal + $rightTotal;
                // factor in yellow cards into total cost here
                if($total > $user->coins){
                    $user->sendError("You don't have enough money to buy those resources");
                    return;
                }

                $user->addCoins(-1 * $total);
                if($leftTotal > 0)
                    $this->tradeQueue[] = array('player' => $user->leftPlayer, 'coins' => $leftTotal);
                if($rightTotal > 0)
                    $this->tradeQueue[] = array('player' => $user->rightPlayer, 'coins' => $rightTotal);
                // give left/right neighbors coins accordingly
                foreach(array('left', 'right') as $side){
                    foreach($args[$side] as $resource => $amount){
                        for($i = 0; $i < $amount; $i++){
                            $res = new Resource();
                            $res->buyable = false;
                            $res->resources = $resource;
                            $user->tempResources[] = $res;
                        }
                    }
                }

                $user->send('bought', array('resources' => $user->tempResources));
                break;

            case 'checkresources':
                $cardName = $args['value'];
                foreach($user->hand as $card)
                    if($card->getName() == $cardName)
                        $toPlay = $card;
                if(!isset($toPlay))
                    return;

                // TODO: this is wrong now
                // $user->send('resourcesneeded', $user->missingCost($toPlay));
                break;

            default:
                echo "Undefined message\n";
                print_r($args);
                break;
        }
   	}
}

?>

