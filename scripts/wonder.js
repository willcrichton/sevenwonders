var WonderBoard = function(args){
    this.name = args.name;
    this.coins = args.coins || 0;
    this.game = args.game;
    this.wonderSide = args.wonderside || 'a';
    this.wonderStage = 0;

    this.wonderDiv = $('<div class="wonderBoard"></div>');
    this.coinDiv = $('<div class="coins"></div>');
    this.militaryDiv = $('<div class="military"></div>');

    this.wonderDiv.css('background', 'url(images/wonders/' + this.name.toLowerCase() + this.wonderSide + '.png) no-repeat center center');

    this.wonderDiv.append(this.coinDiv);
    this.wonderDiv.append(this.militaryDiv);

    if(args.miltary)
        this.updateMilitary(args.military);
    this.updateCoins(this.coins);

    for (i = 0; i < args.stage; i++)
        this.buildWonderStage();
}

WonderBoard.prototype = {
    updateCoins: function(coins){
        this.coins = coins;

        var golds = Math.floor(this.coins / 3);
        var silvers = this.coins % 3;
        this.coinDiv.html('');
        for(var i = 0; i < silvers; i++){
            var rot = (Math.random() - 0.5) * 300;
            var img = $('<img src="images/tokens/coin1.png" class="silver" />');
            img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
            this.coinDiv.append(img);
        }
        this.coinDiv.append('<br />');

        for(var i = 0; i < golds; i++){
            var rot = (Math.random() - 0.5) * 300;
            var img = $('<img src="images/tokens/coin3.png" class="gold" />');
            img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
            this.coinDiv.append(img);
        }
    },

    updateMilitary: function(args){
        this.militaryDiv.html('');
        var points = {1 : 'victory1', 3: 'victory3', 5: 'victory5'};
        points[-1] = 'victoryminus1';
        for(var i in points){
            var n = args[i];
            for(var j = 0; j < n; j++){
                var img = $('<img src="images/tokens/' + points[i] + '.png" />');
                img.css({'-webkit-transform': 'rotate(' + rot + 'deg)', '-moz-transform': 'rotate(' + rot + 'deg)'});
                var rot = (Math.random() - 0.5) * 100;
                this.militaryDiv.append(img);
            }
            if(n > 0) this.militaryDiv.append('<br />');
        }
    },

    moveToBoard: function(card, animate){
        var state = card.data('state');
        card.data('state', '');
        card.addClass('played').attr('id', '');
        if (state == 'trashing') {
            card.animate({
                left: (Math.random() > 0.5 ? '-=' : '+=') + (Math.random() * 200),
                bottom: (Math.random() > 0.5 ? '-=' : '+=') + (Math.random() * 200),
                opacity: 0,
            }, 500, function(){ $(this).remove(); });
            return;
        } else if (state == 'building') {
            card.animate({
                // todo: animate to board where wonder is
            }).fadeOut(200);
            this.buildWonderStage();
            return;
        }

        var infoPos = this.wonderDiv.offset();
        var cardColor = card.data('cardInfo').color;
        var index = this.game.colorOrder.indexOf(cardColor);
        var numInColor = 0;
        for(i in this.game.cardsPlayed)
            if(this.game.cardsPlayed[i].data('cardInfo').color == cardColor) numInColor++;

        card.find('.options, h1').css('display', 'none');
        card.css('z-index', 100 - numInColor);
        var opts = {
            left: infoPos.left / this.game.scale + index * 135,
            bottom: ($('#game').height() - infoPos.top - 155) * this.game.scale + numInColor * 40 - (cardColor == 'blue' ? 93 : 0),
            width: this.game.cardWidth,
            height: this.game.cardHeight,
            opacity: 1,
            rotate: 0
        };

        if (animate)
            card.animate(opts);
        else
            card.css(opts);

        this.game.cardsPlayed.push(card);
        card.removeClass('highlighted').removeClass('selected');
    },

    buildWonderStage: function() {
        this.wonderStage++;
        var check = $('<div class="check stage' + this.wonderStage + '"></div>');
        this.wonderDiv.append(check);
        var offset = 48 + 240 * (this.wonderStage - 1);
        if(this.name == "gizah" && this.wonderSide == 'b'){
            offset = this.wonderStage == 1 ? 3 : 208 * (this.wonderStage - 1);
        } else if(this.name == "rhodos" && this.wonderSide == 'b'){
            offset = 283 + 240 * (this.wonderStage - 1);
        }
        check.css('left', offset);
        check.fadeIn(200);
    }
}