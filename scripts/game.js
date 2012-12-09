var SevenWonders = function(socket, args){

    this.wonder = args.wonder;
    this.players = args.plinfo;
    this.coins = parseInt(args.coins);
    this.socket = socket;
    this.cardsPlayed = [];
    this.leftPlayed = {};
    this.rightPlayed = {};
    this.trashing = false;
    this.neighbors = args.neighbors;
    this.colorOrder = ['brown', 'grey', 'yellow', 'red', 'green', 'purple', 'blue'];

    // select wonder image here (load in appropriately)
    // TODO: let player choose wonder side
    $('#wonder').css('background', 'url(images/wonders/' + this.wonder.toLowerCase() + 'A.png) no-repeat center center');

    var panelOut = false;
    $('#resources a').click(function(){
        if(panelOut){
            $('#resourceSelect').animate({height: $('#resources').height(), bottom: 5}, 500);
        } else {
            $('#resourceSelect').animate({height: $('#resources').height() * 2.2, bottom: $('#resources').height()}, 500);
        }
        panelOut = !panelOut;
    });

    var self = this;
    $('#resourceSelect a').click(function(){
        var info = {'left': {}, 'right': {}};
        $('#resourceSelect input[type=text]').each(function(){
            var args = $(this).attr('name').split('_');
            if(parseInt($(this).attr('value')) > 0){
                info[args[0]][args[1]] = parseInt($(this).attr('value'));
            }
        })
        self.send(info, 'trade')
    });
    this.updateCoins();
    this.updateMilitary(args.military);
    var i;
    for (i = 0; i < args.leftcards.length; i++)
        this.updateColumn('left', args.leftcards[i].color,
                          this.cardImageFromName(args.leftcards[i].name), 0);
    for (i = 0; i < args.rightcards.length; i++)
        this.updateColumn('right', args.rightcards[i].color,
                          this.cardImageFromName(args.rightcards[i].name), 0);
    for (i = 0; i < args.played.length; i++) {
        var div = this.cardDiv(0, args.played[i]);
        $('#game').prepend(div);
        this.moveToBoard(div, false);
    }
}

SevenWonders.prototype = {
    cardWidth: 123,
    cardHeight: 190,

    send: function(opts, type){
        opts = (typeof opts == "object" && !(opts instanceof Array)) ? opts : {value: opts};
        opts.messageType = type;
        this.socket.send(JSON.stringify(opts));
    },

    cardImageFromName: function(name){
        return '<img src="images/cards/' + name.toLowerCase().replace(/ /g, "") + '.png" />';
    },

    updateCoins: function() {
        var golds = Math.floor(this.coins / 3);
        var silvers = this.coins % 3;
        $('#coins').html('');
        for(var i = 0; i < silvers; i++){
            var rot = (Math.random() - 0.5) * 300;
            var img = $('<img src="images/tokens/coin1.png" class="silver" />');
            img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
            $('#coins').append(img);
        }
        $('#coins').append('<br />');

        for(var i = 0; i < golds; i++){
            var rot = (Math.random() - 0.5) * 300;
            var img = $('<img src="images/tokens/coin3.png" class="gold" />');
            img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
            $('#coins').append(img);
        }
    },

    updateMilitary: function(args) {
        $('#military').html('');
        var points = {1 : 'victory1', 3: 'victory3', 5: 'victory5'};
        points[-1] = 'victoryminus1';
        for(var i in points){
            var n = args[i];
            for(var j = 0; j < n; j++){
                var img = $('<img src="images/tokens/' + points[i] + '.png" />');
                img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
                var rot = (Math.random() - 0.5) * 100;
                $('#military').append(img);
            }
            if(n > 0) $('#military').append('<br />');
        }
    },

    updateColumn: function(side, color, img, speed) {
        img = $('<div class="card ignore played">' + img + '</div>');
        var cardsPlayed = side == 'left' ? this.leftPlayed : this.rightPlayed;
        if(cardsPlayed[color] == undefined) cardsPlayed[color] = [];
        var length = cardsPlayed[color].length;
        img.appendTo('.neighbor.' + side);
        var bottom = -160;
        var lastIndex = 0;
        for(var i = this.colorOrder.indexOf(color); i >= 0; i--){
            var col = this.colorOrder[i];
            lastIndex = i;
            if(cardsPlayed[col] && cardsPlayed[col].length > 0){
                var topCard = cardsPlayed[col][cardsPlayed[col].length - 1];
                bottom = img.get(0) == topCard ? 0 : parseInt($(topCard).css('bottom')) + 40;
                break;
            }
        }
        for(var j = lastIndex + 1; j < this.colorOrder.length; j++){
            for(cIndex in cardsPlayed[this.colorOrder[j]]){
                var card_move = $(cardsPlayed[this.colorOrder[j]][cIndex]);
                card_move.animate({bottom: '+=40px'}, speed);
            }
        }
        img.css('bottom', bottom);
        img.css('z-index', 1000 * (8 - this.colorOrder.indexOf(color)) - length);
        cardsPlayed[color].push(img.get(0));
        img.animate({opacity: 1}, speed);
    },

    moveToBoard: function(card, animate) {
        card.addClass('played').attr('id', '');
        if (this.trashing) {
            this.trashing = false;
            card.animate({
                left: (Math.random() > 0.5 ? '-=' : '+=') + (Math.random() * 200),
                bottom: (Math.random() > 0.5 ? '-=' : '+=') + (Math.random() * 200),
                opacity: 0,
            }, 500, function(){ $(this).remove(); });
            return;
        }

        var infoPos = $('#wonder').position();
        var cardColor = card.data('cardInfo').color;
        var index = this.colorOrder.indexOf(cardColor);
        var numInColor = 0;
        for(i in this.cardsPlayed)
            if(this.cardsPlayed[i].data('cardInfo').color == cardColor) numInColor++;

        card.find('.options, h1').css('display', 'none');
        card.css('z-index', 100 - numInColor);
        var opts = {
            left: infoPos.left - 400 + index * 135,
            bottom: $('#game').height() - infoPos.top - 155 + numInColor * 40 - (cardColor == 'blue' ? 93 : 0),
            width: this.cardWidth,
            height: this.cardHeight,
            opacity: 1,
            rotate: 0
        };

        if (animate)
            card.animate(opts);
        else
            card.css(opts);

        this.cardsPlayed.push(card);
        card.removeClass('highlighted').removeClass('selected');
    },

    cardDiv: function(idx, card) {
        var div = $('<div class="card" id="card' + idx +
                 '" style="background: #' + this.colorOrder[card.color] + ';">'+
                     '<h1>' + card.name + '</h1>' +
                     this.cardImageFromName(card.name) +
                     '<div class="options">\
                         <a href="#" class="trash"></a>\
                         <a href="#" class="play"></a>\
                         <a href="#" class="wonder"></a>\
                     </div>\
                     <div class="slider"><h2>Play Cost</h2>\
                            <div class="left"></div>\
                            <input type="range" min="0" value="0"/>\
                            <div class="right"></div>\
                    </div>\
                 </div>');
        div.data('cardInfo', card);
        return div;
    },

    resetHighlight: function(){
        var old = $('.highlighted');
        if(old.length > 0){
            old.removeClass('highlighted');
            old.find('.options a').css('visibility', 'visible')
                                  .animate({opacity: 1}, 200);
            old.find('.play').css('background-image', 'url(images/tokens/card.png)');
        }
    },

    onMessage: function(args, msg){
        switch(args.messageType){
            case 'hand':
                args.cards = $.map(args.cards, function(k,v){ return [k]; });

                $('#resources #current').html('');
                $('#age').html(args.age);
                $('.card').addClass('ignore');
                $('.card:not(.highlighted, .played)').fadeOut(500, function(){
                    $(this).remove();
                });

                var self = this;

                // move selected card to board for later reference
                var selected = $('.card.highlighted');
                if(selected.length){
                    // TODO: animate this rotation (animateTo doesn't work)
                    this.moveToBoard(selected, true);
                }

                // animate selected to board

                var count = args.cards.length;
                for(i in args.cards){
                    var card = args.cards[i];
                    var div = this.cardDiv(count, card);
                    $('#game').prepend(div);
                    count--;
                }

                function cardIndex(card){
                    return parseInt($(card).attr('id').substring(4,5)) - 1;
                }

                /* * * * * * * * * * * * * * * * * * * * * * * * * * * * *
                * This is where we start handling animations for cards.  *
                * It gets pretty messy. There's a lot of interface stuff *
                * going on to check edge cases and what not.             *
                *             I am not proud of this code.               *
                * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

                // Put new cards at start position and rotate them accordingly
                var numCards = args.cards.length;
                $('#game').css('overflow', 'hidden');
                $('.card').each(function(){
                    if($(this).hasClass('ignore')) return;
                    var deg = (cardIndex(this) + 0.5 - numCards / 2) * 8;
                    $(this).css({
                        'left': $('#wonder').position().left - 75,
                        'bottom': -200
                    });
                    $(this).rotate(deg);
                    $(this).data('rotation', deg);
                })

                // card dealing animation
                setTimeout(function(){
                    $('.card:not(.ignore)').each(function(){
                        var index = cardIndex(this);
                        $(this).animate({
                            'bottom': '+=' + ((Math.pow(index + 0.5 - numCards / 2, 2) * -8) + 665),
                            'left': '+=' + (index + 0.5 - numCards / 2) * 120
                        }, 1500, 'easeOutExpo', function(){
                            $('#game').css("overflow", "auto");
                        });
                    });
                }, 1000);

                // card blow up animation (on click)
                $('.card').click(function(e){
                    e.stopPropagation();
                    if($(this).is(':animated') || $(this).hasClass('ignore')) return;
                    if($(this).hasClass('selected')){
                        $(this).css('z-index', 1);
                        $(this).animate({
                            width: self.cardWidth, 
                            height: self.cardHeight, 
                            left: '+=25px', 
                            bottom: '+=38px',
                            rotate: $(this).data('rotation')
                        }, 200);
                        $(this).removeClass('selected');
                        $('.card:not(.ignore)').animate({ opacity: 1 }, 200);
                        $('.options').css('display', 'none');
                    } else {
                        $('.card.selected').css('z-index', 1);
                        $('.card.selected').each(function(){
                            $(this).animate({
                                width: self.cardWidth, 
                                height: self.cardHeight, 
                                left: '+=25px', 
                                bottom: '+=38px',
                                rotate: $(this).data('rotation')
                            }, 200);
                            $(this).find('.options, .slider').css('display', 'none');
                        });
                        $('.card:not(.ignore)').removeClass('selected');
                        $(this).addClass('selected');
                        $('.card:not(.ignore, #' + $(this).attr('id') + ')').animate({ opacity: 0.1 }, 200);
                        $(this).animate({
                            width: self.cardWidth + 50,
                            height: self.cardHeight + 76.5,
                            left: '-=25px',
                            bottom: '-=38px',
                            opacity: 1,
                            rotate: 0
                        }, 200, function(){
                            $(this).find('.options').fadeIn(200);
                            $(this).css('z-index', 2);
                        });
                    }
                });

                function chooseCard(card, playtype){
                    card.addClass('highlighted');
                    self.send([card.find('h1').html(), playtype], 'cardplay');
                }

                $('.trash').click(function(e){
                    e.stopPropagation()
                    var card = $(this).parent().parent();
                    self.resetHighlight();

                    card.addClass('highlighted');
                    self.send([card.find('h1').html(), 'trash'], 'cardplay');
                    self.trashing = true;
                    
                    card.find('.options a:not(.play)').animate({opacity: 0}, 200)
                                                      .css('visibility', 'hidden');
                    card.find('.options .play').css('background-image', 'url(images/tokens/no.png');

                    return false;
                });

                $('.play').click(function(e){
                    e.stopPropagation()
                    var card = $(this).parent().parent();
                    if(card.hasClass('highlighted')){
                        $(this).animate({opacity: 0}, 200, function(){
                            self.resetHighlight();
                        });
                        self.send('', 'cardignore');
                    } else {
                        self.send({value: card.find('h1').html()}, 'checkresources');
                    }
                    self.trashing = false;
                    return false;
                });

            break;

            case 'canplay':
                var card = $('.highlighted');
                card.find('.options a').animate({ opacity: 0 }, 200, function(){
                    if($(this).hasClass('play')){
                        $(this).css('background-image', 'url(images/tokens/no.png)')
                               .animate({opacity: 1}, 200);
                    } else {
                        $(this).css('visibility', 'hidden');
                    }                
                });
            break;

            case 'cardschosen':
                if (args.left)
                    this.updateColumn('left', args.left.color,
                                      this.cardImageFromName(args.left.name), 200);
                if (args.right)
                    this.updateColumn('right', args.right.color,
                                      this.cardImageFromName(args.right.name), 200);
            break;

            case 'coins':
                this.coins = args.data;
                this.updateCoins();
                break;

            case 'military':
                this.updateMilitary(args);
                break;

            case 'possibilities':
                var card = $('.card.selected');
                if(!args.combs[0]){
                    card.append('<div class="overlay"><h2>Error</h2>It\'s impossible to play this card</div>');
                    card.find('.overlay').animate({ opacity: '0.9' }, 200);
                    card.find('img').animate({opacity: '0.3'}, 200);
                    var removeErr = function(card){
                        card.find('.overlay').animate({ opacity: 0 }, 200, function(){
                            $(this).remove();
                        });
                        card.find('img').css('opacity', '1');
                    }
                    card.find('.overlay').click(function(e){ e.stopPropagation(); removeErr(card); })
                    setTimeout(function(){ removeErr(card) }, 2000);
                } else {
                    var minCost = 100;
                    for(var i in args.combs){
                        var combo = args.combs[i]; 
                        var cost = combo.left + combo.right;
                        if(cost < minCost) minCost = cost;
                    }
                    if(minCost == 0 || typeof args.combs[0].left == "undefined"){
                        this.resetHighlight();
                        $('.card:not(.ignore)').removeClass('highlighted');
                        card.addClass('highlighted');
                        this.send([card.find('h1').html(), 'play', 0], 'cardplay');
                    } else {
                        var combos = [];
                        for(var i in args.combs){
                            var combo = args.combs[i];
                            var cost = combo.left + combo.right;
                            if(cost <= minCost + 1){
                                combos.push(combo);
                            }
                            combo.index = i;
                        }
                        combos.sort(function(a, b){ return a.left < b.left });
                        var firstMin = 0;
                        for(var i = 0; i < combos.length; i++){
                            if(combos[i].left + combos[i].right == minCost){
                                firstMin = i; break;
                            }
                        }

                        $('.card.selected .options a:not(.play)').animate({opacity: 0}, 200);
                        $('.card.selected .slider .left').html(combos[firstMin].left);
                        $('.card.selected .slider .right').html(combos[firstMin].right);
                        $('.card.selected .slider input[type=range]').attr('max', combos.length - 1)
                                                                     .attr('value', firstMin);
                        $('.card.selected .slider').click(function(e){ e.stopPropagation(); })
                            .css({'height': '0', display: 'block'})
                            .animate({
                                height: 65
                            }, 200);
                        $('.card.selected input[type=range]').change(function(){
                            var val = $(this).attr('value');
                            $('.card.selected .slider .left').html(combos[val].left);
                            $('.card.selected .slider .right').html(combos[val].right);
                        });

                        var self = this;
                        $('.card.selected .play').css('background-image', 'url(images/tokens/buy.png)')
                            .unbind('click')
                            .click(function(e){
                                e.stopPropagation();
                                var combo = combos[$('.card.selected .slider input[type=range]').attr('value')];
                                self.resetHighlight();
                                card.addClass('highlighted');
                                self.send([card.find('h1').html(), 'play', combo.index], 'cardplay');
                                card.find('.slider').animate({height: 0}, 200, function(){
                                    $(this).css('display', 'none');
                                });
                            });
                    }
                }
                break;

            case 'error':
                // todo: more fancy alerts
                if($('.card.selected').length){
                    
                }   else {
                    alert(args.data)
                }
            break;

            default:
                console.log(args, msg);
            break;
        }
    }
}
