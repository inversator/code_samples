<template>
    <div>
        <cams-balance-timer
            :model-title="modelName"
            :prices="prices"
            :full-screen="fullScreenMode"
            :is-connected="isConnected"
            :show-type="selectedShow"
            @time-is-up="disconnectUser(30)"
            :balance="balance"></cams-balance-timer>

        <div id="hybrid-client"
             ref="camsPlayer"
             data-whitelabel="sinpartylive.com"
             data-isBlacklabel="true"
             :data-sakey="sakey"
             :data-name="modelName"
             :data-lang="lang"
             data-esf="true"
             data-hidebiotab="true"
             data-shownFeatures="similarModels">
            <span id="hybrid-loader" class="loading-spinner">
                <svg width="50" height="50">
                    <use href="#loading-spinner"/>
                </svg>
            </span>
        </div>
    </div>
</template>

<script>
import CamsBalanceTimer from './ShowLowBalance/Timer.vue'
import {api_url, sp_cookie, setCookie} from '@/frontend/main'
import { pushBalanceEcommerceGA } from '@/frontend/analytics'
import {mapState} from 'vuex'

const { site_url, dataLayer } = window

export default {
    name: 'CamsPlayer',
    components: { CamsBalanceTimer },
    props: {
        saKey: String,

        userBalance: Number,
        userId: Number,

        modelName: String,
        countryIso: String,

        liveOrientation: {
            type: String,
            default: '/live',
        },

        locale: {
            type: String,
            default: 'en',
        },

        userFake: Boolean,
        testMode: {
            type: Boolean,
            default: false
        },
    },
    data() {
        return {
            lang: 'en',
            allowedLanguages: ['de', 'en', 'es', 'fr', 'it', 'nl', 'no', 'pt', 'sv'],
            linkPrefix: '',
            sakey: this.saKey,
            balance: this.userBalance,

            clientDiv: null,
            reloadAttempts: 0,
            prices: {
                premium: 0,
                exclusive: 0
            },
            selectedShow: 'premium',
            siteUrl: site_url,
            fullScreenMode: false,
            isConnected: true,
            playerNodes: {
                privateBtn: '[data-ta-locator="CtaContainer__enterPaidButton"]',
                mainVideoContainer: '[data-ta-locator="OverlayContainer"]',
                goldBoxInput: '[data-ta-locator=GoldBox__input]',
                goldModalSubmit: '[data-ta-locator=GiveGoldModal__submitButton]',
                goldSpinModalSubmit: '[data-ta-locator=SpinGaveGold]',
                goldMenuModalSubmit: '[data-ta-locator=GoldMenu__SendGoldButton]',
                closeModal: '[data-ta-locator^=CloseModalButton__closeButton]'
            },
            lastClicked: null,
            orientation: null,
            playerLoaded: false,
            redirectOn: true
        }
    },
    computed: {
        ...mapState('Page', ['attributionId']),

        intendedAction() {
            return localStorage.getItem('camsIntendedAction')
        },
        justLoggedIn() {
            return localStorage.getItem('camsLoggedIn')
        },
        justToppedUp: function () {
            let referrer = document.referrer
            if (referrer) {
                let url = new URL(referrer)
                return url.pathname === '/billing/pp/success'
            }

            return false
        }
    },
    mounted() {
        this.setLocale()

        const hpl = document.getElementById('hybrid-pre-loader')
        this.$refs.camsPlayer.style.setProperty('--login-display', this.userId ? 'none' : 'block')

        if (hpl) {
            hpl.style.display = 'none'
        }

        this.clientDiv = document.getElementById('hybrid-client')

        // Event listeners
        if (this.userId && window.Echo) {
            window.Echo.private('user.' + this.userId)
                .listen('.App\\Events\\UserBalanceChanged', (e) => {
                    this.$updateBalance(e.balance)
                    this.balance = parseFloat(e.balance);
                    if (e.type == 'private' && e.amount && e.amount > 0) pushBalanceEcommerceGA({ 'transaction_type': 'private show', 'tokens_amount': +e.amount })
                });
        }

        this.playerEventProcessor();

        // Hide performer player if reloading
        window.addEventListener('beforeunload', function () {
            document.querySelector('[data-ta-locator=Sidebar__SidebarContainer]').innerHTML = ''
        });

        this.setOrientation()

        if (this.orientation == 'landscape') {
            document.querySelector('.header')?.classList.add('bar--hide')
            document.querySelector('.cam-model__top')?.classList.add('bar--hide')
            document.querySelector('.footer-mobile')?.classList.add('bar--hide')
            setTimeout(() => {
                zE('messenger', 'hide')
            }, 1000)
        } else {
            document.querySelector('.cam-model__top').style.display = 'flex'
        }

        this.setVH()

        // Player side setting for orientation change
        window.addEventListener('orientationchange', () => {
            setTimeout(this.orientationChanged, 500)
        }, false);

        window.addEventListener('resize', () => {
            this.setVH()
        })

        this.nodeObserver();

        this.$root.$on('resize', () => {
            this.playerReloadHandler()
        })

        this.$on('show-stopped', () => {
            // const smcSelector = '[class*=SimilarModels__SimilarModelsContainer]'
            // let similarModelContainer = document.querySelector(smcSelector)
            //
            // if (similarModelContainer) {
            //     similarModelContainer.style.display = 'block'
            // } else {
            //     setTimeout(() => {
            //         similarModelContainer = document.querySelector(smcSelector)
            //
            //         if (similarModelContainer) {
            //             similarModelContainer.style.display = 'block'
            //         }
            //     }, 1000)
            // }
        })

        // In desctop fullscreen mode, when we click chat icon switching from photo tab
        document.querySelector('body').addEventListener('click', event => {
            if (event.target.matches('[data-ta-locator=TabsContainer__chat]')) {
                setTimeout(() => this.$emit('chat-icons-reloaded'), 200)
            } else if (event.target.matches('[data-ta-locator=VideoHeaderDisconnectButton]') || event.target.closest('[data-ta-locator=VideoHeaderDisconnectButton]')) {

                // If close was emmited by user
                if (event.isTrusted && this.redirectOn) {
                    this.clientBrowseHandler()
                }
            }
        })
    },
    methods: {
        setLocale() {
            if (this.allowedLanguages.includes(this.locale)) {
                this.lang = this.locale
            }

            this.linkPrefix = '/' + this.locale

            if (this.locale == 'en') {
                this.linkPrefix = ''
            }
        },
        setShowPrices() {
            let regexp = new RegExp(/([\d.]+)/, 'gi');
            let keys = { 0: 'premium', 1: 'exclusive' };

            for (let i = 0; i < 2; i++) {
                let modalBlock = document.querySelector('[data-ta-locator^=EnterPaidModal__RadioButton__' + keys[i] + ']')

                if (!modalBlock) {
                    return false
                }

                let rer = modalBlock.textContent.match(regexp);

                if (rer && rer[0])
                    this.prices[keys[i]] = rer[0];
                else
                    console.error(keys[i] + ' show price receiving error');
            }

            return true;
        },
        getPrivatePrice() {
            let enterShowBtn = document.querySelector('[data-ta-locator^=EnterPaidModal__enterPaidButton]')
            let radios = document.querySelectorAll('[class*=RadioButtonGroup-root] input')

            if (!this.setShowPrices()) {
                setTimeout(() => this.setShowPrices(), 1000)
            }

            setTimeout(() => {
                for (var i = 0, max = radios.length; i < max; i++) {
                    radios[i].onclick = e => {
                        this.selectedShow = e.target.value;
                    }
                }
            }, 500)

            // Listen enter show btn
            if (enterShowBtn) {
                enterShowBtn.onclick = e => {

                    if (this.prices[this.selectedShow] && this.balance < this.prices[this.selectedShow]) {
                        e.stopImmediatePropagation()
                        this.safeClick(this.playerNodes.closeModal)

                        localStorage.setItem('camsIntendedAction', 'private')
                        this.showLowBalancePopup()
                    } else {
                        this.$emit('show-started')
                        this.redirectOn = false
                        pushBalanceEcommerceGA({ 'transaction_type': 'private show', 'tokens_amount': +this.prices[this.selectedShow] })
                    }
                }

                // For test
                if (this.testMode) {
                    const privateModalHeader = document.querySelector('[class^=ModalHeader]')

                    if (privateModalHeader) {
                        privateModalHeader.onclick = () => {
                            this.$emit('show-started')
                            pushBalanceEcommerceGA({ 'transaction_type': 'private show', 'tokens_amount': +this.prices[this.selectedShow] })
                        }
                    }
                }
            } else {
                console.info('Enter show btn not found')
            }
        },
        clickProcessor(type, event = null) {
            if (event) {
                dataLayer.push(event)
            }

            // If user logged in bl account
            if (this.sakey) {

                if (this.balance >= 1) {
                    this.saveIntention(this.userId, type, this.modelName, window.location.href);
                } else {
                    setTimeout(() => this.safeClick(this.playerNodes.closeModal), 1000)
                    localStorage.setItem('camsIntendedAction', type == 'private' ? 'private' : 'gold')
                    this.showLowBalancePopup()
                }

                // Get private show prices
                if (type == 'private') {
                    setTimeout(this.getPrivatePrice, 1000)
                }
            }

            this.clientSubmitButtons()
        },
        videoAndCtaContainerClickHandler: function (e) {
            this.lastClicked = e.target;

            // Restrict use on test environment
            if (process.env.APP_ENV !== 'production' && this.userId && !this.userFake) {
                setTimeout(() => this.safeClick(this.playerNodes.closeModal), 1000);

                this.$root.showModal({
                    popupClass: 'popup--sinparty-live',
                    title: '<svg class="icon" width="26" height="26">' +
                        '<use xlink:href="#exclamation-triangle"/></svg> ' +
                        'Operation restricted',
                    body: 'Cams operations allowed only for the production environment'
                })

                return
            }

            // Report complaint btn pushed
            const closestBtn = e.target.parentElement.closest('button')
            const popoverRCid = 'popover_reportcomplaintbutton'

            if ((closestBtn && closestBtn.id === popoverRCid) || e.target.id === popoverRCid) {
                this.playerPopupEqualizer()
            }

            // For case when svg icon inside btn clicked
            switch (closestBtn && closestBtn.dataset.taLocator) {
                case 'VideoHeaderDisconnectButton':
                    console.info('Disconnect inquired')
                    this.$emit('show-stopped')

                    this.isConnected = false
                    break

                case 'VibeButtonCompact':
                    this.clickProcessor('give_gold')
                    break

                case 'VibeButton':
                    this.clickProcessor('give_gold')
                    break

                case 'GoldMenuButton':
                    this.clickProcessor('give_gold')
                    break

                case 'SpinGoldButton':
                    this.clickProcessor('give_gold')
                    break
            }

            switch (e.target.closest('[data-ta-locator]')?.dataset.taLocator) {
                case 'VideoDisplay__EnterClampedState':
                    this.fullScreenOpened()
                    break;
                case 'VideoDisplay__ExitClampedState':
                    this.fullScreenClosed()
                    break;
            }

            switch (e.target.dataset.taLocator) {
                case 'VideoHeaderDisconnectButton':
                    console.info('Disconnect inquired')
                    this.isConnected = false

                    break;
                case 'VideoDisplay__EnterClampedState':
                    this.fullScreenOpened()
                    break;
                case 'VideoDisplay__ExitClampedState':
                    this.fullScreenClosed()
                    break;

                case 'VibeButtonCompact':
                    this.clickProcessor('give_gold')
                    break;

                case 'VibeButton':
                    this.clickProcessor('give_gold')
                    break;

                case 'GoldMenuButton':
                    this.clickProcessor('give_gold')
                    break;

                case 'SpinGoldButton':
                    this.clickProcessor('give_gold')
                    break;

                case 'CtaContainer__enterPaidButton':
                    this.clickProcessor('private', { 'event': 'blacklabel_private' })
                    break;
                case 'CtaContainer__giveGoldButton':
                    this.clickProcessor(e.target.children[0].textContent === 'Give GOLD' ? 'give_gold' : 'goldshow',
                        {
                            'event': e.target.children[0].textContent === 'Give GOLD'
                                ? 'blacklabel_give_gold'
                                : 'blacklabel_gold_show'
                        });

                    // Check if user has enough funds on the balance
                    setTimeout(() => {
                        document.querySelector(this.playerNodes.goldModalSubmit)?.addEventListener('click', e => {

                            const givenAmount = document.querySelector(this.playerNodes.goldBoxInput)?.value;

                            if (this.balance < givenAmount) {
                                e.stopImmediatePropagation()
                                this.safeClick(this.playerNodes.closeModal)

                                localStorage.setItem('camsIntendedAction', 'gold')
                                this.showLowBalancePopup()
                            }

                        }, true)
                    }, 200)

                    break;
            }

            // Click on main video frame
            if (e.target.tagName.toLowerCase() === 'video') {
                this.clickProcessor('private')
            }
        },
        // User has indicated they want to go "Browse Live Models". Navigate to the appropriate location.
        clientBrowseHandler() {
            window.location = this.siteUrl + this.linkPrefix + this.liveOrientation
        },

        // The client has entered fullscreen mode.
        clientOpenFullScreenHandler() {
            this.fullScreenOpened()
        },

        // The client has exited fullscreen mode.
        clientCloseFullscreenHandler() {
            this.fullScreenClosed()
        },

        // The player has requested to refresh the page.
        clientRefreshHandler() {
            window.location.reload()
        },
        loginHandler() {
            this.$showModal('ModalLogin', { 'source': 'cams' })
        },
        // Player action demands authorization
        clientAuthHandler() {

            // If chat messages throw auth required — don't process
            if (this.lastClicked &&
                ['ChatForm__Input', 'ChatTools__send', 'ChatForm__Input__fake'].some(k => this.lastClicked.dataset.taLocator == k)
            ) {
                return
            }

            if (this.lastClicked) {
                if (this.lastClicked.dataset.taLocator === 'Offline__signUpButton') {
                    this.$showModal('ModalLogin', { 'source': 'cams' })
                    return
                }
            }

            if (!this.userId) {
                for (let className of this.lastClicked.classList) {
                    if (className.startsWith('video-videoWrapper-videoElement')) {
                        this.$showModal('ModalLogin', { 'camsAction': 'private', 'source': 'cams' })
                        return
                    }
                }

                let inquiredAction = this.lastClicked.dataset.taLocator == 'CtaContainer__enterPaidButton'
                    ? 'private'
                    : 'gold'

                this.$showModal('ModalLogin', { 'camsAction': inquiredAction, 'source': 'cams' })
            } else {
                this.reloadAttempts++

                if (this.reloadAttempts > 2) {
                    this.$root.showModal({
                        popupClass: 'popup--sinparty-live',
                        title: '<svg class="icon" width="26" height="26">' +
                            '<use xlink:href="#exclamation-triangle"/></svg> ' +
                            this.$t('vuejs.cams.auth.user-join-error'),
                        body: this.$t('vuejs.cams.auth.account-cant-join') +
                            '<a href="/contact" class="gtm_click_btn btn btn--primary">' +
                            this.$t('vuejs.modals.contact-support') + '</a>'
                    })
                } else {
                    this.sakey = this.liveCamsAuth(this.modelName)
                }
            }
        },

        // The user should contact support; there is a problem with their account.
        clientSupportHandler() {
            dataLayer.push({ 'event': 'CAMS_SUPPORT_POPUP' })
            this.$root.showModal({
                popupClass: 'popup--sinparty-live',
                title: `<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> ${this.$t('vuejs.cams.support.title')}`,
                body: `<p>${this.$t('vuejs.cams.support.body')}</p><a href="${this.linkPrefix}/contact" class="gtm_click_btn btn btn--primary">${this.$t('vuejs.cams.support.contact_support')}</a>`
            })
        },

        /* ESF only - Our backend has failed to process an external site fund transaction.
        Display a billing error message to the user and contact streamate for more details */
        clientBillFailHandler() {
            this.$emit('show-stopped')
            dataLayer.push({ 'event': 'ESF_BILLING_FAIL' })

            this.$root.showModal({
                popupClass: 'popup--sinparty-live',
                title: `<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> ${this.$t('vuejs.cams.billing_failed.billing_failure')}`,
                body: `<p>${this.$t('vuejs.cams.billing_failed.body')}</p>
                       <a href="${this.linkPrefix}/contact" class="gtm_click_btn btn btn--primary">${this.$t('vuejs.cams.support.contact_support')}</a>`
            })
        },

        /* ESF only - When the user attempts to enter a private or exclusive show with insufficient funds
        in their account, the user should be notified that their funds are too low and will need to be restocked */
        clientLowFundsHandler() {
            dataLayer.push({ 'event': 'SM_LOW_FUNDS' })
            // eslint-disable-next-line no-undef
            this.showLiveCamLowFundsWarning()
        },

        // The user has clicked on a similar model/performer shown in the client
        clientSwitchPerformer(e) {
            if (e.detail) {
                dataLayer.push({ 'event': 'internal_affiliate_live_cams' })
                dataLayer.push({ 'event': 'live_show_click', 'creator_name': e.detail.nickname.toLowerCase() })
                window.location = this.linkPrefix + this.liveOrientation + '/' + e.detail.nickname.toLowerCase()
            }
        },

        // Client disconnected
        clientDisconnected() {
            this.isConnected = false
            this.$emit('show-stopped')
        },

        // Client spend golds
        clientSubmitButtons() {
            setTimeout(() => {
                document.querySelector(this.playerNodes.goldModalSubmit)?.addEventListener('click', () => {
                    const givenAmount = document.querySelector(this.playerNodes.goldBoxInput)?.value;
                    pushBalanceEcommerceGA({ 'transaction_type': 'Give Gold', 'tokens_amount': +givenAmount })
                })
                document.querySelector(this.playerNodes.goldSpinModalSubmit)?.addEventListener('click', () => {
                    pushBalanceEcommerceGA({ 'transaction_type': 'Spin', 'tokens_amount': 8 })
                })
                document.querySelector(this.playerNodes.goldMenuModalSubmit)?.addEventListener('click', () => {
                    pushBalanceEcommerceGA({ 'transaction_type': 'Spin' })
                })
            }, 500);
        },

        playerEventProcessor() {
            this.$refs.camsPlayer.addEventListener('click', this.videoAndCtaContainerClickHandler)

            this.$refs.camsPlayer.addEventListener('SM_BROWSE_LIVE_MODELS', this.clientBrowseHandler)
            this.$refs.camsPlayer.addEventListener('SM_BROWSE_PERFORMERS', this.clientBrowseHandler)
            this.$refs.camsPlayer.addEventListener('SM_DISCONNECTED', this.clientDisconnected)
            this.$refs.camsPlayer.addEventListener('SM_ENTER_FULLSCREEN', this.clientOpenFullScreenHandler)
            this.$refs.camsPlayer.addEventListener('SM_EXIT_FULLSCREEN', this.clientCloseFullscreenHandler)
            this.$refs.camsPlayer.addEventListener('SM_REFRESH', this.clientRefreshHandler)
            this.$refs.camsPlayer.addEventListener('SM_GOTO_LOGIN', this.loginHandler)
            this.$refs.camsPlayer.addEventListener('SM_REQUIRES_AUTH', this.clientAuthHandler)
            this.$refs.camsPlayer.addEventListener('SM_REQUIRES_SUPPORT', this.clientSupportHandler)
            this.$refs.camsPlayer.addEventListener('SM_ESF_BILLING_FAIL', this.clientBillFailHandler)
            this.$refs.camsPlayer.addEventListener('SM_LOW_FUNDS', this.clientLowFundsHandler)
            this.$refs.camsPlayer.addEventListener('SM_SWITCH_PERFORMER', this.clientSwitchPerformer)

            this.$on('player-loaded', this.playerReloadHandler)
            this.$on('mobile-chat-loaded', this.mobileChatLoadedHandler)
            this.$on('chat-icons-reloaded', this.replaceChatSvgIcons)
        },
        safeClick(selector) {
            let element = document.querySelector(selector)
            if (element)
                element.click();
            else
                console.error('selector not found', selector)
        },

        async saveIntention(user_id, type, model_title, link) {
            fetch(api_url + '/v2/web/live-cams/user/intention', {
                headers: {
                    'Content-Type': 'application/json;charset=utf-8',
                    'sp-api-key': sp_cookie,
                },
                body: JSON.stringify({
                    user_id: user_id,
                    type: type,
                    model_title: model_title,
                    link: link,
                    attribution_id: this.attributionId,
                }),
                method: 'POST',
                cache: 'no-cache'
            }).then((response) => {
                response.json().then(data => {
                    console.info(data)
                })

            }).catch((error) => {
                console.error({ error });
            });
        },

        async liveCamsAuth() {
            this.$root.showModal({
                popupClass: 'popup--sinparty-live',
                title: `<img src="/resources/img/icons/loading.svg" style="height: 30px; margin: -10px 0px 0px; padding: 0px;"/> ${this.$t('vuejs.cams.auth.verifying-account')}`,
                body: `<p>${this.$t('vuejs.cams.auth.please-wait-verification')}...</p>`
            });

            let response = await fetch(api_url + '/v2/web/live-cams/user/auth', {
                headers: {
                    'Content-Type': 'application/json;charset=utf-8',
                    'sp-api-key': sp_cookie,
                },
                cache: 'no-cache'
            });

            if (response.ok) {
                return response.json().then(result => {

                    // Set cookie for the session
                    setCookie('sp-api-key', result.data.session.session_token, 7);

                    // Check if this is the first black label verification for this account and should be tracked
                    if (result.data.track === 1) {
                        dataLayer.push({ 'event': 'blacklabel_complete' });
                    }

                    if (typeof this.$root.closeModal === 'function') {
                        this.$root.closeModal()
                    } else {
                        setTimeout(() => document.querySelector('button[class=popup__close]').click(), 500)
                    }

                    this.$refs.camsPlayer.dataset.sakey = result.data.response.sakey;

                    return result.data.response.sakey;
                });

            } else {
                response.json().then(result => {
                    if (result.data) {

                        // Check if this is the first black label verification for this account and should be tracked
                        if (result.data.track === 1) {
                            dataLayer.push({ 'event': 'blacklabel_complete' });
                        }

                        // INVALID_PARAMETERS, ACCESS_DENIED, USER_NOT_FOUND
                        if (result.data.status === 'SM_ERROR') {
                            this.$root.showModal({
                                popupClass: 'popup--sinparty-live',
                                title: `<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> ${this.$t('vuejs.cams.errors.user-join.title')}`,
                                body: `${this.$t('vuejs.cams.errors.user-join.body')}`
                            })

                            // SM_REQUIRES_PAYMENT_INFO, SM_REQUIRES_SUPPORT
                        } else {

                            // USER_SPENDING_RESTRICTED, USER_ACCOUNT_RESTRICTED, USER_ACCOUNT_CLOSED
                            this.$root.showModal({
                                popupClass: 'popup--sinparty-live',
                                title: `<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> ${this.$t('vuejs.cams.errors.account-restricted.title')}`,
                                body: `${this.$t('vuejs.cams.errors.account-restricted.body')}`
                            })

                            this.$root.showModal({
                                popupClass: 'popup--sinparty-live',
                                title: '<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> test',
                                body: 'test'
                            })
                        }

                        // Response didn't return
                    } else {
                        let message = result.message || '';
                        let error = result.error || '';

                        this.$root.showModal({
                            popupClass: 'popup--sinparty-live',
                            title: `<svg class="icon" width="26" height="26"><use xlink:href="#exclamation-triangle"/></svg> ${this.$t('vuejs.cams.errors.server-error.title')}`,
                            body: '<p>' + message + '</p><p>' + error + `</p>${this.$t('vuejs.cams.errors.server-error.body')}`
                        })
                    }
                });
            }
        },

        fullScreenClosed() {
            if (this.fullScreenMode) {
                console.info('exitClampedState')
                document.querySelector('.header')?.classList.remove('bar--hide')
                document.querySelector('.cam-model__top')?.classList.remove('bar--hide')
                document.querySelector('.footer-mobile')?.classList.remove('bar--hide')
                this.fullScreenMode = false
                zE('messenger', 'show')

                this.changeFullscreenIcon()
                this.replaceChatSvgIcons()

                const ssContainer = document.querySelector('[data-ta-locator=Sidebar__SidebarContainer]')
                if (ssContainer) {
                    ssContainer.style.opacity = 1
                }

                // Round player's video container
                if (window.innerWidth > 947) {
                    const vvContainer = document.querySelector('[class*=VideoDisplay__VideoDisplayContainer]')
                    vvContainer.style.borderRadius = '16px'
                }

                this.changeFlagSprite()

                const videoCtaContainer = document.querySelector('[data-ta-locator=VideoAndCtaContainer]')
                if (videoCtaContainer) {
                    videoCtaContainer.style.gap = '11px !important'
                }

                const complainBtnSvg = document.querySelector('[id=popover_reportcomplaintbutton]')
                if (complainBtnSvg) {
                    complainBtnSvg.style.display = 'inline-block'
                }
            }
        },

        fullScreenOpened() {
            window.scrollTo(0, 0);

            if (!this.fullScreenMode) {
                document.querySelector('.header')?.classList.add('bar--hide')
                document.querySelector('.cam-model__top')?.classList.add('bar--hide')
                document.querySelector('.footer-mobile')?.classList.add('bar--hide')

                console.info('enterClampedState')
                this.fullScreenMode = true;
                zE('messenger', 'hide');

                const fullScreenSvg = document.querySelector('[data-ta-icon=fullscreenExit] svg')
                fullScreenSvg?.setAttribute('viewBox', '3 2 18 18')

                const fullScreenPath = document.querySelector('[data-ta-icon=fullscreenExit] path')
                fullScreenPath?.setAttribute('stroke', '')
                fullScreenPath?.setAttribute('stroke-width', '')
                fullScreenPath?.setAttribute('stroke-linecap', '')

                const vvContainer = document.querySelector('[class*=VideoDisplay__VideoDisplayContainer]')
                vvContainer.style.borderRadius = '0'

                const videoCtaContainer = document.querySelector('[data-ta-locator=VideoAndCtaContainer]')

                if (videoCtaContainer) {
                    videoCtaContainer.style.gap = '0px !important'
                }

                const complainBtnSvg = document.querySelector('[id=popover_reportcomplaintbutton]')

                if (complainBtnSvg) {
                    complainBtnSvg.style.display = 'none'
                }
            }
        },

        orientationChanged() {

            this.setOrientation()
            this.setModalCloserPosition()

            setTimeout(() => {
                if (this.orientation === 'portrait') {
                    document.querySelector('.header')?.classList.remove('bar--hide')
                    document.querySelector('.cam-model__top')?.classList.remove('bar--hide')
                    document.querySelector('.footer-mobile')?.classList.remove('bar--hide')
                    zE('messenger', 'show')
                } else {
                    document.querySelector('.header')?.classList.add('bar--hide')
                    document.querySelector('.cam-model__top')?.classList.add('bar--hide')
                    document.querySelector('.footer-mobile')?.classList.add('bar--hide')
                    zE('messenger', 'hide')
                }
            }, 600)
        },

        beforeDestroy() {
        },

        repeatIntendedAction() {
            if (!this.userId || (!this.intendedAction && !this.justLoggedIn)) {
                return;
            }

            if (this.balance >= 1 && this.intendedAction && (this.justToppedUp || this.justLoggedIn)) {
                const icf = this.intendedAction === 'private' ? 'GoPrivate' : 'GiveGold'
                document.querySelector(`[data-icf-click=${icf}Button]`)?.click()
                localStorage.removeItem('camsIntendedAction')

            } else if (this.balance <= 1 && this.justLoggedIn) {
                const topUpMessage = `
                    <p>${this.$t('vuejs.cams.top-up.your-wallet-balance-is', { balance: this.balance })}</p>
                    <h3>${this.$t('vuejs.cams.top-up.top-up-now')}!</h3>
                    <button class="modal-auth__submit cams-player--top-up-btn"
                    onclick="hideModal(); $showModal('ModalTopUpBalance', { justTopUp: true, source: 'cams' })">
                    ${this.$t('vuejs.cams.top-up.top-up')}
                    </button>
                  `

                this.$root.showModal({
                    popupClass: 'popup--sinparty-live',
                    title: `${this.$t('vuejs.modals.login-successful')}`,
                    body: topUpMessage,
                })

                localStorage.removeItem('camsLoggedIn')
            }

            if (this.justLoggedIn) {
                localStorage.removeItem('camsIntendedAction')
            }
        },
        playerPopupEqualizer() {
            if (!this.fullScreenMode)
                setTimeout(() => {

                    let reportPopover = document.querySelector('[class^=Popover-root]')
                    if (reportPopover) {

                        if (reportPopover.querySelector('[class*=Popover-topTriangle]')) {
                            return
                        }

                        let rect = document.querySelector('[data-ta-locator^=VideoAndCtaContainer]')?.getBoundingClientRect()

                        if (rect && rect?.height) {
                            let newReportPopupIndent = rect?.height - (reportPopover.getBoundingClientRect().height / 2)

                            if (window.innerWidth < 948) {
                                newReportPopupIndent = document.getElementById('popover_reportcomplaintbutton')?.getBoundingClientRect()?.y - 80 + window.pageYOffset
                            } else if (window.innerWidth < 1200) {
                                newReportPopupIndent -= (window.innerWidth / 100 * 2) - 47
                            } else {
                                newReportPopupIndent -= (window.innerWidth / 100 * 2) - 91
                            }

                            if (reportPopover) {
                                reportPopover.style.top = newReportPopupIndent + 'px'
                                reportPopover.style.bottom = 'unset'
                            }
                        }

                        document.querySelector('[data-testid=ReportStreamingIssueButton]')?.addEventListener('click', () => {
                            this.playerPopupReportEqualizer()
                        })
                    }
                }, 100)
        },
        playerPopupReportEqualizer() {
            if (window.innerWidth > 1049 || window.innerWidth < 948)
                setTimeout(() => {

                    let reportPopover = document.querySelector('[class^=Popover-root]')
                    if (reportPopover) {

                        if (reportPopover.querySelector('[class*=Popover-topTriangle]')) {
                            return
                        }

                        let rect = document.querySelector('[data-ta-locator^=VideoAndCtaContainer]')?.getBoundingClientRect();

                        if (rect && rect?.height) {
                            let newReportPopupIndent = document.getElementById('popover_reportcomplaintbutton')?.getBoundingClientRect()?.y
                                + window.pageYOffset
                                - reportPopover?.getBoundingClientRect()?.height

                            if (reportPopover) {
                                reportPopover.style.top = newReportPopupIndent + 'px'
                                reportPopover.style.bottom = 'unset'
                            }
                        }
                    }
                }, 200)
        },
        mobileChatLoadedHandler() {
            if (window.innerWidth < 948) {
                console.info('mobile chat loaded')
                document.querySelector('[data-ta-locator=Chat__togglePrivacy] svg')?.setAttribute('viewBox', '0 0 27 28')
                document.querySelector('[data-ta-locator=ChatForm__mic] svg')?.setAttribute('viewBox', '2 0 20 24')
                this.changeMobileTabs()

                document.querySelector('[data-ta-locator=ChatForm__Input]').addEventListener('focus', () => {
                    zE('messenger', 'hide');

                    document.querySelector('.header')?.classList.add('bar--hide')
                    document.querySelector('.cam-model__top')?.classList.add('bar--hide')
                    document.querySelector('.footer-mobile')?.classList.add('bar--hide')
                })

                document.querySelector('[data-ta-locator=ChatForm__Input]').addEventListener('blur', () => {
                    zE('messenger', 'show')

                    document.querySelector('.header')?.classList.remove('bar--hide')
                    document.querySelector('.cam-model__top')?.classList.remove('bar--hide')
                    document.querySelector('.footer-mobile')?.classList.remove('bar--hide')
                })
            }
        },
        changeMobileTabs() {

            // Chat mobile tab
            const chatSvgPath = document.querySelector('[data-ta-locator=TabsContainer__chat] path')
            const chatSvg = document.querySelector('[data-ta-locator=TabsContainer__chat] svg')

            chatSvg?.setAttribute('viewBox', '0 0 23 20')

            chatSvgPath?.setAttribute('d', 'M18.3 1H4.7C3.57989 1 3.01984 1 2.59202 1.21799C2.21569 1.40973 1.90973 1.71569 1.71799 2.09202C1.5 2.51984 1.5 3.0799 1.5 4.2V17.7056C1.5 18.1342 1.5 18.3485 1.59027 18.4776C1.6691 18.5903 1.79086 18.6655 1.92692 18.6856C2.07518 18.7075 2.25592 18.6218 2.60335 18.4483C2.62365 18.4382 2.6338 18.4331 2.64376 18.4276C2.65257 18.4226 2.66133 18.4174 2.66983 18.412C2.67942 18.4058 2.68871 18.3993 2.70725 18.3862L2.70734 18.3861L6.66989 15.5865L6.6699 15.5865C6.97818 15.3687 7.13232 15.2598 7.30048 15.1825C7.44972 15.114 7.60684 15.0641 7.76828 15.034C7.95019 15 8.13892 15 8.51639 15H18.3C19.4201 15 19.9802 15 20.408 14.782C20.7843 14.5903 21.0903 14.2843 21.282 13.908C21.5 13.4802 21.5 12.9201 21.5 11.8V4.2C21.5 3.0799 21.5 2.51984 21.282 2.09202C21.0903 1.71569 20.7843 1.40973 20.408 1.21799C19.9802 1 19.4201 1 18.3 1Z')
            chatSvgPath?.setAttribute('stroke', '#AAAAAA')
            chatSvgPath?.setAttribute('stroke-width', 2)


            // Info mobile tab
            const infoSvgPath = document.querySelector('[data-ta-locator=TabsContainer__info] path')
            const infoSvg = document.querySelector('[data-ta-locator=TabsContainer__info] svg')

            infoSvg?.setAttribute('viewBox', '1 2 23 20')

            infoSvgPath?.setAttribute('d', 'M12.5 17V11M12.5 8V7.99M23.5 12C23.5 18.0751 18.5751 23 12.5 23C6.42487 23 1.5 18.0751 1.5 12C1.5 5.92487 6.42487 1 12.5 1C18.5751 1 23.5 5.92487 23.5 12Z')
            infoSvgPath?.setAttribute('stroke', '#AAAAAA')
            infoSvgPath?.setAttribute('stroke-width', 2)
            infoSvgPath?.setAttribute('stroke-linecap', 'round')


            // Photo mobile tab
            const photoSvgPath = document.querySelector('[data-ta-locator=TabsContainer__photos] path')
            const photoSvg = document.querySelector('[data-ta-locator=TabsContainer__photos] svg')

            photoSvg?.setAttribute('viewBox', '0 0 25 24')

            photoSvgPath?.setAttribute('d', 'M1.5 10L13.7929 22.2929C14.1834 22.6834 14.8166 22.6834 15.2071 22.2929L23.5 14M7.5 15L11.7929 10.7071C12.1834 10.3166 12.8166 10.3166 13.2071 10.7071L19.5 17M18.1248 5.62494H18.8748M18.1248 6.37494H18.8748M9.5 23H15.5C18.3003 23 19.7004 23 20.77 22.455C21.7108 21.9757 22.4757 21.2108 22.955 20.27C23.5 19.2004 23.5 17.8003 23.5 15V9C23.5 6.19974 23.5 4.79961 22.955 3.73005C22.4757 2.78924 21.7108 2.02433 20.77 1.54497C19.7004 1 18.3003 1 15.5 1H9.5C6.69974 1 5.29961 1 4.23005 1.54497C3.28924 2.02433 2.52433 2.78924 2.04497 3.73005C1.5 4.79961 1.5 6.19974 1.5 9V15C1.5 17.8003 1.5 19.2004 2.04497 20.27C2.52433 21.2108 3.28924 21.9757 4.23005 22.455C5.29961 23 6.69974 23 9.5 23ZM19.5 6C19.5 6.55228 19.0523 7 18.5 7C17.9477 7 17.5 6.55228 17.5 6C17.5 5.44772 17.9477 5 18.5 5C19.0523 5 19.5 5.44772 19.5 6Z')
            photoSvgPath?.setAttribute('stroke', '#AAAAAA')
            photoSvgPath?.setAttribute('stroke-width', 2)
            photoSvgPath?.setAttribute('stroke-linecap', 'round')
        },
        playerReloadHandler() {
            // Mark player as loaded, then turn it to false so new reload events can be tracked
            setTimeout(() => {
                this.playerLoaded = false
            }, 3000)

            setTimeout(this.setCountryFont, 500)

            // Repeat intended action
            setTimeout(this.repeatIntendedAction(), 1000)

            this.replaceChatSvgIcons()
            this.replacePlayerSvgIcons()
            this.changeFlagSprite()

            const ssContainer = document.querySelector('[data-ta-locator=Sidebar__SidebarContainer]')
            const cvContainer = document.querySelector('[class*=CtaContainer__VideoControlsContainer]')

            if (ssContainer) {
                ssContainer.style.opacity = 1
            }

            if (cvContainer) {
                cvContainer.style.opacity = 1
            }

            // Set width of btn bar side panel
            const sidebarContainer = document.querySelector('[data-ta-locator=Sidebar__SidebarContainer]')

            if (sidebarContainer && document.querySelector('.cam-model__btn-bar--side')) {
                document.querySelector('.cam-model__btn-bar--side').style.width = sidebarContainer.offsetWidth + 'px'
            }

            // document.querySelector('.cam-model__btn-bar').style.display = 'flex'
        },
        createGradientElement() {
            const SVG_NS = 'http://www.w3.org/2000/svg';

            const defs = document.createElementNS(SVG_NS, 'defs')
            const gradient = document.createElementNS(SVG_NS, 'linearGradient')

            gradient?.setAttribute('id', 'header-shape-gradient')
            gradient?.setAttribute('x2', '0.35')
            gradient?.setAttribute('y2', '1')

            const stops = [
                { offset: '0%', color: 'var(--color-stop)' },
                { offset: '30%', color: 'var(--color-stop)' },
                { offset: '100%', color: 'var(--color-bot)' }
            ];

            for (const s of stops) {
                const stopElem = document.createElementNS(SVG_NS, 'stop')
                stopElem?.setAttribute('offset', s.offset)
                stopElem?.setAttribute('stop-color', s.color)
                gradient?.appendChild(stopElem)
            }

            defs?.appendChild(gradient)
            return defs
        },

        nodeObserver() {
            // Select the node that will be observed for mutations
            let targetNode = this.$refs.camsPlayer

            let config = { attributes: false, childList: true, subtree: true }

            let callback = (mutationsList) => {

                // Look through all mutations that just occured
                for (let mutation of mutationsList) {

                    // If the addedNodes property has one or more nodes
                    if (mutation.addedNodes.length) {
                        for (let node of mutation.addedNodes) {

                            if (node.dataset?.taLocator === 'ClientModal') {
                                const previousPhotoBtn = document.querySelector('[data-ta-locator=PhotoViewerComponents__NavigationButtonPrevious]')
                                const nextPhotoBtn = document.querySelector('[data-ta-locator=PhotoViewerComponents__NavigationButtonNext]')

                                if (previousPhotoBtn) {
                                    previousPhotoBtn.querySelector('svg')?.setAttribute('viewBox', '0 0 14 25')
                                    previousPhotoBtn.querySelector('path')?.setAttribute('d', 'M13.2975 3.08348C13.4511 2.94031 13.5744 2.76766 13.6598 2.57583C13.7453 2.384 13.7913 2.17691 13.795 1.96693C13.7987 1.75695 13.7601 1.54838 13.6814 1.35365C13.6028 1.15892 13.4857 0.982033 13.3372 0.833532C13.1887 0.68503 13.0118 0.567961 12.8171 0.489307C12.6223 0.410653 12.4138 0.372027 12.2038 0.375732C11.9938 0.379437 11.7867 0.425397 11.5949 0.510871C11.4031 0.596345 11.2304 0.719582 11.0872 0.873229L0.661523 11.2989C0.516199 11.4442 0.400916 11.6166 0.322262 11.8064C0.243609 11.9962 0.203125 12.1997 0.203125 12.4051C0.203125 12.6106 0.243609 12.814 0.322262 13.0038C0.400916 13.1936 0.516199 13.3661 0.661523 13.5113L11.0872 23.937C11.3823 24.2217 11.7774 24.3792 12.1875 24.3754C12.5975 24.3717 12.9897 24.207 13.2795 23.9169C13.5693 23.6268 13.7336 23.2345 13.737 22.8245C13.7403 22.4144 13.5825 22.0195 13.2975 21.7247L3.97899 12.4041L13.2975 3.08348Z')

                                    previousPhotoBtn.querySelector('path')?.setAttribute('fill', '#aaa')
                                }

                                if (nextPhotoBtn) {
                                    nextPhotoBtn.querySelector('svg')?.setAttribute('viewBox', '0 0 14 25')
                                    nextPhotoBtn.querySelector('path')?.setAttribute('d', 'M0.702509 21.6675C0.548861 21.8107 0.425625 21.9833 0.340151 22.1751C0.254676 22.367 0.208717 22.5741 0.205012 22.784C0.201307 22.994 0.239933 23.2026 0.318586 23.3973C0.397241 23.5921 0.51431 23.7689 0.662811 23.9174C0.811313 24.0659 0.988203 24.183 1.18293 24.2617C1.37766 24.3403 1.58623 24.3789 1.79621 24.3752C2.00619 24.3715 2.21328 24.3256 2.40511 24.2401C2.59694 24.1546 2.76959 24.0314 2.91276 23.8777L13.3385 13.452C13.4838 13.3068 13.5991 13.1344 13.6777 12.9446C13.7564 12.7548 13.7969 12.5513 13.7969 12.3459C13.7969 12.1404 13.7564 11.937 13.6777 11.7472C13.5991 11.5574 13.4838 11.3849 13.3385 11.2397L2.91276 0.813975C2.61768 0.529242 2.22257 0.371798 1.81253 0.375552C1.4025 0.379308 1.01034 0.543961 0.720521 0.834052C0.430704 1.12414 0.26642 1.51646 0.263051 1.9265C0.259683 2.33654 0.417499 2.7315 0.702511 3.02631L10.021 12.3469L0.702509 21.6675Z')
                                    nextPhotoBtn.querySelector('path')?.setAttribute('fill', '#aaa')
                                }
                            }

                            if (typeof node.getAttribute !== 'function' || typeof node.querySelector !== 'function') return;

                            if (node.querySelector('[class^="ChatForm__Container"]')) {
                                this.$emit('mobile-chat-loaded')
                            }

                            if (node.querySelector('[class*="ChatTools__ChatToolsContainer"]')) {
                                console.log('Chat icons reloaded')
                                this.$emit('chat-icons-reloaded')
                            }

                            if (!this.playerLoaded && node.id == 'video-container') {
                                this.playerLoaded = true
                                console.info('Player loaded')
                                this.$emit('player-loaded')
                            }

                            if (typeof node.className.split === 'function') {
                                let classes = node.className.split(' ')

                                for (let className of classes) {

                                    if (!this.playerLoaded && ['VideoInfo__Container', 'VideoDisplay__ResizeDragger'].some(k => className.startsWith(k))) {
                                        this.playerLoaded = true;
                                        console.info('Player loaded');
                                        this.$emit('player-loaded')
                                    }

                                    if (className.startsWith('Modal__OverlayMobile')) {
                                        setTimeout(() => {
                                            this.setModalCloserPosition()
                                        }, 100)
                                    }

                                    if (className.startsWith('TabsContainer__StyledTabsContainer')) {
                                        this.$emit('mobile-chat-loaded')
                                    }
                                }
                            }

                            if (node.querySelector('[data-ta-locator="ChatTools__send"]')) {
                                node.querySelector('[data-ta-locator="ChatTools__send"]')
                                    .addEventListener('click', () => dataLayer.push({ 'event': 'live_show_chat_message', 'creator_name': this.modelName }))
                            }
                        }
                    }
                }
            };

            let observer = new MutationObserver(callback);
            observer.observe(targetNode, config);
        },
        showLowBalancePopup() {
            this.$root.showModal({
                popupClass: 'popup--sinparty-live',
                title: `${this.$t('vuejs.cams.top-up.wallet-balance-is-low')}`,
                body: `<p>${this.$t('vuejs.cams.top-up.your-wallet-balance-is', { balance: this.balance })}</p>` +
                    `<h3>${this.$t('vuejs.cams.top-up.top-up-now')}!</h3>` +
                    `<button class="modal-auth__submit cams-player--top-up-btn"
                        onclick="hideModal(); $showModal('ModalTopUpBalance', { justTopUp: true, source: 'cams' })">
                    ${this.$t('vuejs.cams.top-up.top-up')}
                    </button>`
            })
        },
        disconnectUser(delay = 1) {
            setTimeout(() => {
                document.querySelector('[data-ta-locator=VideoHeaderDisconnectButton]')?.click()
            }, delay * 1000)
        },

        setOrientation() {
            let angle = typeof screen.orientation === 'undefined'
                ? window.innerWidth > window.innerHeight
                : screen.orientation.angle

            if (window.innerWidth > 947) {
                this.orientation = 'portrait'
            } else {
                this.orientation = angle ? 'landscape' : 'portrait'
            }
        },
        setModalCloserPosition() {
            const mcContainer = document.querySelector('[class*=Modal__CloserContainer]')

            if (this.orientation != 'landscape') {
                let modalContainerRect = document.querySelector('[class*=Modal__InnerContentWrapper]')?.getBoundingClientRect()

                if (mcContainer) {
                    mcContainer.style.top = modalContainerRect.top - 100 + window.pageYOffset + 'px'
                }
            } else if (mcContainer) {
                mcContainer.style.top = 5 + '%'
            }
        },
        setVH() {
            let vh = window.innerHeight * 0.01
            document.documentElement.style.setProperty('--vh', `${vh}px`)
        },
        replaceChatSvgIcons() {
            const starElements = document.querySelectorAll('[class^="Rating__Star"] svg');

            starElements.forEach(svg => {
                svg.appendChild(this.createGradientElement());
            });

            // Guest count
            const guestSvgPath = document.querySelector('[class*=GuestCount__Text] path');
            const guestSvg = document.querySelector('[class*=GuestCount__Text] svg');

            guestSvg?.setAttribute('viewBox', '0 0 16 16')

            guestSvgPath?.setAttribute('d', 'M8.00065 9.66659C10.3018 9.66659 12.1673 7.8011 12.1673 5.49992C12.1673 3.19873 10.3018 1.33325 8.00065 1.33325C5.69946 1.33325 3.83398 3.19873 3.83398 5.49992C3.83398 7.8011 5.69946 9.66659 8.00065 9.66659ZM8.00065 9.66659C4.31875 9.66659 1.33398 11.9052 1.33398 14.6666M8.00065 9.66659C11.6825 9.66659 14.6673 11.9052 14.6673 14.6666')
            guestSvgPath?.setAttribute('stroke', '#AAAAAA')
            guestSvgPath?.setAttribute('stroke-width', 2)
            guestSvgPath?.setAttribute('stroke-linecap', 'round')

            // Font size
            if (document.querySelectorAll('[class*=FontSizeChanger__Container] path')?.length === 2) {
                let fontSvgPath2 = document.querySelector('[class*=FontSizeChanger__Container] path')
                fontSvgPath2?.remove()
            }

            const fontSvgPath = document.querySelector('[class*=FontSizeChanger__Container] path')
            const fontSvg = document.querySelector('[class*=FontSizeChanger__Container] svg');
            fontSvg?.setAttribute('viewBox', '0 0 18 16')

            fontSvgPath?.setAttribute('d', 'M1.5 7.99992H4.83333M4.83333 7.99992H8.16667M4.83333 7.99992V14.6666M6.5 1.33325H11.5M11.5 1.33325H16.5M11.5 1.33325V14.6666')
            fontSvgPath?.setAttribute('stroke', '#AAAAAA')
            fontSvgPath?.setAttribute('stroke-width', 2)
            fontSvgPath?.setAttribute('stroke-linecap', 'round')

            // Quick chat message
            const quickChatSvgPath = document.querySelector('[class*=QuickChat__Container] path');
            const quickChatSvg = document.querySelector('[class*=QuickChat__Container] svg');
            quickChatSvg?.setAttribute('viewBox', '0 0 20 18')

            quickChatSvgPath?.setAttribute('d', 'M6.66602 4.66667C6.11373 4.66667 5.66602 5.11438 5.66602 5.66667C5.66602 6.21895 6.11373 6.66667 6.66602 6.66667V4.66667ZM13.3327 6.66667C13.885 6.66667 14.3327 6.21895 14.3327 5.66667C14.3327 5.11438 13.885 4.66667 13.3327 4.66667V6.66667ZM6.66602 8C6.11373 8 5.66602 8.44772 5.66602 9C5.66602 9.55228 6.11373 10 6.66602 10V8ZM9.99935 10C10.5516 10 10.9993 9.55228 10.9993 9C10.9993 8.44772 10.5516 8 9.99935 8V10ZM18.151 12.2567L19.042 12.7106V12.7106L18.151 12.2567ZM17.4227 12.985L17.8767 13.876V13.876L17.4227 12.985ZM17.4227 1.68166L17.8767 0.79065V0.790649L17.4227 1.68166ZM18.151 2.41002L19.042 1.95603V1.95602L18.151 2.41002ZM2.57603 1.68166L3.03002 2.57266L2.57603 1.68166ZM1.84767 2.41002L2.73868 2.86401L1.84767 2.41002ZM2.67213 15.9884L3.24905 16.8052L3.24916 16.8051L2.67213 15.9884ZM5.97426 13.6554L5.39723 12.8387L5.39723 12.8387L5.97426 13.6554ZM1.74124 16.0646L2.5607 15.4915L2.5607 15.4915L1.74124 16.0646ZM2.58547 16.0403L2.13874 15.1456L2.13872 15.1456L2.58547 16.0403ZM2.02178 16.238L1.87562 17.2273H1.87562L2.02178 16.238ZM2.64088 16.01L2.10172 15.1678L2.10158 15.1678L2.64088 16.01ZM2.61915 16.023L2.13213 15.1496L2.13207 15.1496L2.61915 16.023ZM6.88958 13.195L7.07306 14.178L7.07307 14.178L6.88958 13.195ZM6.49975 13.3188L6.91713 14.2275L6.91713 14.2275L6.49975 13.3188ZM6.66602 6.66667H13.3327V4.66667H6.66602V6.66667ZM6.66602 10H9.99935V8H6.66602V10ZM4.33268 2.5H15.666V0.5H4.33268V2.5ZM17.3327 4.16667V10.5H19.3327V4.16667H17.3327ZM17.3327 10.5C17.3327 10.9832 17.3319 11.2855 17.3133 11.5132C17.2956 11.7293 17.267 11.7889 17.26 11.8027L19.042 12.7106C19.2167 12.3679 19.2789 12.0159 19.3067 11.6761C19.3335 11.348 19.3327 10.9502 19.3327 10.5H17.3327ZM15.666 14.1667C16.1162 14.1667 16.514 14.1674 16.8421 14.1406C17.1819 14.1129 17.5339 14.0506 17.8767 13.876L16.9687 12.094C16.9549 12.101 16.8953 12.1296 16.6792 12.1473C16.4515 12.1659 16.1492 12.1667 15.666 12.1667V14.1667ZM17.26 11.8027C17.1961 11.9281 17.0941 12.0301 16.9687 12.094L17.8767 13.876C18.3784 13.6204 18.7864 13.2124 19.042 12.7106L17.26 11.8027ZM15.666 2.5C16.1492 2.5 16.4515 2.50078 16.6792 2.51939C16.8953 2.53704 16.9549 2.56564 16.9687 2.57266L17.8767 0.790649C17.5339 0.616021 17.1819 0.553788 16.8421 0.526028C16.514 0.499222 16.1162 0.5 15.666 0.5V2.5ZM19.3327 4.16667C19.3327 3.71646 19.3335 3.31866 19.3067 2.99057C19.2789 2.65081 19.2167 2.29875 19.042 1.95603L17.26 2.86401C17.267 2.8778 17.2956 2.93736 17.3133 3.15344C17.3319 3.38119 17.3327 3.68346 17.3327 4.16667H19.3327ZM16.9687 2.57266C17.0941 2.63658 17.1961 2.73856 17.26 2.86401L19.042 1.95602C18.7864 1.45426 18.3784 1.04631 17.8767 0.79065L16.9687 2.57266ZM4.33268 0.5C3.88247 0.5 3.48467 0.499222 3.15659 0.526028C2.81683 0.553788 2.46477 0.616021 2.12204 0.790649L3.03002 2.57266C3.04381 2.56564 3.10337 2.53704 3.31945 2.51939C3.5472 2.50078 3.84947 2.5 4.33268 2.5V0.5ZM2.66602 4.16667C2.66602 3.68346 2.66679 3.38119 2.6854 3.15344C2.70306 2.93736 2.73165 2.8778 2.73868 2.86401L0.956665 1.95603C0.782037 2.29875 0.719803 2.65081 0.692044 2.99057C0.665238 3.31866 0.666016 3.71646 0.666016 4.16667H2.66602ZM2.12204 0.790649C1.62028 1.04631 1.21233 1.45426 0.956665 1.95603L2.73868 2.86401C2.80259 2.73856 2.90458 2.63658 3.03002 2.57266L2.12204 0.790649ZM15.666 12.1667H7.51301V14.1667H15.666V12.1667ZM3.24916 16.8051L6.55129 14.4721L5.39723 12.8387L2.0951 15.1717L3.24916 16.8051ZM0.666016 15.4213C0.666016 15.5804 0.665019 15.7686 0.679581 15.9277C0.694012 16.0854 0.733296 16.3683 0.921786 16.6378L2.5607 15.4915C2.61859 15.5743 2.64682 15.6501 2.66009 15.6971C2.67206 15.7395 2.67275 15.7617 2.67126 15.7454C2.66985 15.73 2.66805 15.6991 2.66704 15.6403C2.66605 15.5819 2.66602 15.5131 2.66602 15.4213H0.666016ZM2.13872 15.1456C2.06601 15.1819 2.01165 15.2088 1.96645 15.2301C1.92069 15.2516 1.89787 15.261 1.88876 15.2644C1.87909 15.2681 1.90197 15.2584 1.94662 15.2505C1.99549 15.2418 2.07303 15.2347 2.16794 15.2487L1.87562 17.2273C2.18663 17.2732 2.44591 17.1918 2.59401 17.136C2.74109 17.0805 2.90029 17.0008 3.03222 16.9349L2.13872 15.1456ZM0.921786 16.6378C1.14512 16.9571 1.49013 17.1703 1.87562 17.2273L2.16794 15.2487C2.32667 15.2722 2.46873 15.36 2.5607 15.4915L0.921786 16.6378ZM2.66602 15.4213V4.16667H0.666016V15.4213H2.66602ZM2.09521 15.1716C2.09111 15.1745 2.08852 15.1763 2.08636 15.1779C2.08424 15.1793 2.08376 15.1797 2.08412 15.1794C2.0853 15.1786 2.09207 15.1739 2.10172 15.1678L3.18003 16.8522C3.2116 16.832 3.2408 16.811 3.24905 16.8052L2.09521 15.1716ZM3.03221 16.9349C3.04162 16.9302 3.07351 16.9146 3.10623 16.8963L2.13207 15.1496C2.1419 15.1441 2.14915 15.1404 2.15061 15.1397C2.15106 15.1394 2.15058 15.1397 2.14832 15.1408C2.146 15.142 2.14319 15.1434 2.13874 15.1456L3.03221 16.9349ZM2.10158 15.1678C2.11163 15.1614 2.12177 15.1553 2.13213 15.1496L3.10616 16.8964C3.13122 16.8824 3.15595 16.8676 3.18017 16.8521L2.10158 15.1678ZM7.51301 12.1667C7.23461 12.1667 6.96959 12.1628 6.7061 12.2119L7.07307 14.178C7.11276 14.1706 7.16229 14.1667 7.51301 14.1667V12.1667ZM6.55129 14.4721C6.83773 14.2698 6.88044 14.2444 6.91713 14.2275L6.08237 12.41C5.83879 12.5219 5.62459 12.678 5.39723 12.8387L6.55129 14.4721ZM6.70611 12.2119C6.49085 12.2521 6.28136 12.3187 6.08237 12.41L6.91713 14.2275C6.96688 14.2047 7.01925 14.188 7.07306 14.178L6.70611 12.2119Z')
            quickChatSvgPath?.setAttribute('fill', '#AAAAAA')

            // Emojis
            const emojiSvgPath = document.querySelector('[class*=EmojiPicker__Container] path')
            const emojiSvg = document.querySelector('[class*=EmojiPicker__Container] svg')

            emojiSvg?.setAttribute('viewBox', '0 0 20 20')

            emojiSvgPath?.setAttribute('d', 'M6.66602 12.5001C6.94379 13.0556 7.99935 14.1667 9.99935 14.1667C11.9993 14.1667 13.0549 13.0556 13.3327 12.5001M6.92633 7.76047H7.23883M6.92633 8.07297H7.23883M12.7597 7.76047H13.0722M12.7597 8.07297H13.0722M18.3327 10.0001C18.3327 14.6025 14.6017 18.3334 9.99935 18.3334C5.39698 18.3334 1.66602 14.6025 1.66602 10.0001C1.66602 5.39771 5.39698 1.66675 9.99935 1.66675C14.6017 1.66675 18.3327 5.39771 18.3327 10.0001ZM7.49935 7.91675C7.49935 8.14687 7.3128 8.33341 7.08268 8.33341C6.85256 8.33341 6.66602 8.14687 6.66602 7.91675C6.66602 7.68663 6.85256 7.50008 7.08268 7.50008C7.3128 7.50008 7.49935 7.68663 7.49935 7.91675ZM13.3327 7.91675C13.3327 8.14687 13.1461 8.33341 12.916 8.33341C12.6859 8.33341 12.4993 8.14687 12.4993 7.91675C12.4993 7.68663 12.6859 7.50008 12.916 7.50008C13.1461 7.50008 13.3327 7.68663 13.3327 7.91675Z')
            emojiSvgPath?.setAttribute('stroke', '#AAAAAA')
            emojiSvgPath?.setAttribute('stroke-width', 2)
            emojiSvgPath?.setAttribute('stroke-linecap', 'round')

            const fullScreenMessageIconPath = document.querySelector('[data-ta-locator=TabsContainer__chat] path')

            fullScreenMessageIconPath?.setAttribute('d', 'M18.8 3H5.2C4.07989 3 3.51984 3 3.09202 3.21799C2.71569 3.40973 2.40973 3.71569 2.21799 4.09202C2 4.51984 2 5.0799 2 6.2V19.7056C2 20.1342 2 20.3485 2.09027 20.4776C2.1691 20.5903 2.29086 20.6655 2.42692 20.6856C2.57518 20.7075 2.75592 20.6218 3.10335 20.4483C3.12365 20.4382 3.1338 20.4331 3.14376 20.4276C3.15257 20.4226 3.16133 20.4174 3.16983 20.412C3.17942 20.4058 3.18871 20.3993 3.20725 20.3862L3.20734 20.3861L7.16989 17.5865L7.1699 17.5865C7.47818 17.3687 7.63232 17.2598 7.80048 17.1825C7.94972 17.114 8.10684 17.0641 8.26828 17.034C8.45019 17 8.63892 17 9.01639 17H18.8C19.9201 17 20.4802 17 20.908 16.782C21.2843 16.5903 21.5903 16.2843 21.782 15.908C22 15.4802 22 14.9201 22 13.8V6.2C22 5.0799 22 4.51984 21.782 4.09202C21.5903 3.71569 21.2843 3.40973 20.908 3.21799C20.4802 3 19.9201 3 18.8 3Z')

            fullScreenMessageIconPath?.setAttribute('stroke-width', 2)
            fullScreenMessageIconPath?.setAttribute('stroke', '#808080')

            const fullScreenPhotoPath = document.querySelector('[data-ta-locator=TabsContainer__photos] path')
            fullScreenPhotoPath?.setAttribute('d', 'M1 10L13.2929 22.2929C13.6834 22.6834 14.3166 22.6834 14.7071 22.2929L23 14M7 15L11.2929 10.7071C11.6834 10.3166 12.3166 10.3166 12.7071 10.7071L19 17M17.6248 5.62494H18.3748M17.6248 6.37494H18.3748M9 23H15C17.8003 23 19.2004 23 20.27 22.455C21.2108 21.9757 21.9757 21.2108 22.455 20.27C23 19.2004 23 17.8003 23 15V9C23 6.19974 23 4.79961 22.455 3.73005C21.9757 2.78924 21.2108 2.02433 20.27 1.54497C19.2004 1 17.8003 1 15 1H9C6.19974 1 4.79961 1 3.73005 1.54497C2.78924 2.02433 2.02433 2.78924 1.54497 3.73005C1 4.79961 1 6.19974 1 9V15C1 17.8003 1 19.2004 1.54497 20.27C2.02433 21.2108 2.78924 21.9757 3.73005 22.455C4.79961 23 6.19974 23 9 23ZM19 6C19 6.55228 18.5523 7 18 7C17.4477 7 17 6.55228 17 6C17 5.44772 17.4477 5 18 5C18.5523 5 19 5.44772 19 6Z')

            fullScreenPhotoPath?.setAttribute('stroke-width', 2)
            fullScreenPhotoPath?.setAttribute('stroke', '#808080')
            fullScreenPhotoPath?.setAttribute('stroke-linecap', 'round')
        },
        replacePlayerSvgIcons() {
            /* Player control btns */

            // Report btn
            const reportSvg = document.querySelector('[data-testid=ReportComplaintButton-button] svg')
            reportSvg?.setAttribute('viewBox', '3 3 20 20')
            if (reportSvg)
                reportSvg.style.fill = '#fff !important'

            // Refresh
            const refreshSvg = document.querySelector('[data-ta-locator=VideoControls__refreshButton] svg')
            const refreshPath = document.querySelector('[data-ta-locator=VideoControls__refreshButton] path')

            refreshSvg?.setAttribute('viewBox', '0 0 20 22')
            refreshPath?.setAttribute('d', 'M19 10.5667C19 15.5372 14.9706 19.5667 10 19.5667C5.02944 19.5667 1 15.5372 1 10.5667C1 5.59609 5.02944 1.56665 10 1.56665C12.3051 1.56665 14.4077 2.43321 16 3.85833M17 1.56665V4.56665C17 5.11894 16.5523 5.56665 16 5.56665H13')
            refreshPath?.setAttribute('stroke', 'white')
            refreshPath?.setAttribute('stroke-width', 2)
            refreshPath?.setAttribute('stroke-linecap', 'round')

            // Video quality control
            if (document.querySelectorAll('[data-ta-locator=VideoControls__streamQuality] path')?.length === 2) {
                const videoControlPath2 = document.querySelector('[data-ta-locator=VideoControls__streamQuality] path')
                videoControlPath2?.remove()
            }

            const videoControlSvg = document.querySelector('[data-ta-locator=VideoControls__streamQuality] svg')
            const videoControlPath = document.querySelector('[data-ta-locator=VideoControls__streamQuality] path')

            videoControlSvg?.setAttribute('viewBox', '0 0 22 23')
            videoControlPath?.setAttribute('d', 'M15 11.5667C15 13.7758 13.2091 15.5667 11 15.5667C8.79086 15.5667 7 13.7758 7 11.5667C7 9.35751 8.79086 7.56665 11 7.56665C13.2091 7.56665 15 9.35751 15 11.5667Z')
            videoControlPath?.setAttribute('stroke', 'white')
            videoControlPath?.setAttribute('stroke-width', '2')

            const newPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            newPath?.setAttribute('d', 'M13 3.08135C13 2.2448 12.3218 1.56665 11.4853 1.56665H10.5147C9.67814 1.56665 9 2.24479 9 3.08131C9 4.43074 7.36846 5.1065 6.41428 4.15231C5.82275 3.56079 4.86369 3.56078 4.27217 4.1523L3.58589 4.83858C2.99434 5.43013 2.99434 6.38923 3.58589 6.98078C4.54014 7.93503 3.86429 9.56665 2.51478 9.56665C1.67819 9.56665 1 10.2448 1 11.0814V12.052C1 12.8885 1.67816 13.5667 2.51471 13.5667C3.86415 13.5667 4.53997 15.1982 3.58577 16.1524C2.99425 16.7439 2.99425 17.703 3.58577 18.2945L4.27209 18.9808C4.86362 19.5723 5.82268 19.5723 6.41422 18.9808C7.36844 18.0266 9 18.7024 9 20.0519C9 20.8885 9.67817 21.5667 10.5147 21.5667H11.4852C12.3218 21.5667 13 20.8884 13 20.0519C13 18.7024 14.6316 18.0265 15.5858 18.9807C16.1774 19.5723 17.1365 19.5723 17.728 18.9807L18.4143 18.2944C19.0058 17.7029 19.0058 16.7439 18.4143 16.1524C17.4601 15.1982 18.1359 13.5667 19.4853 13.5667C20.3219 13.5667 21 12.8885 21 12.052V11.0814C21 10.2448 20.3218 9.56665 19.4853 9.56665C18.1358 9.56665 17.46 7.93505 18.4142 6.98082C19.0057 6.38927 19.0057 5.43018 18.4142 4.83864L17.7279 4.15237C17.1364 3.56083 16.1773 3.56084 15.5858 4.15238C14.6316 5.10659 13 4.4308 13 3.08135Z')
            newPath?.setAttribute('stroke', 'white')
            newPath?.setAttribute('stroke-width', '2')

            videoControlSvg?.appendChild(newPath)

            // Fullscreen
            this.changeFullscreenIcon()

            // Volume
            const volumeSvg = document.querySelector('[data-ta-locator=VolumeControls__muteButton] svg')
            volumeSvg?.setAttribute('viewBox', '0 0 23 23')
        },
        changeFullscreenIcon() {
            if (!this.fullScreenMode) {
                const fullScreenSvg = document.querySelector('[data-ta-locator=VideoControls__fullscreenButton] svg')
                const fullScreenPath = document.querySelector('[data-ta-locator=VideoControls__fullscreenButton] path')
                fullScreenSvg?.setAttribute('viewBox', '0 0 24 24')

                fullScreenPath?.setAttribute('d', 'M3 8.96665V6.44665C3 5.43856 3 4.93451 3.19619 4.54947C3.36876 4.21078 3.64413 3.93541 3.98282 3.76284C4.36786 3.56665 4.87191 3.56665 5.88 3.56665H8.4M15.6 3.56665L18.12 3.56665C19.1281 3.56665 19.6321 3.56665 20.0172 3.76284C20.3559 3.93541 20.6312 4.21078 20.8038 4.54947C21 4.93451 21 5.43856 21 6.44665V8.96665M21 16.1667V18.6866C21 19.6947 21 20.1988 20.8038 20.5838C20.6312 20.9225 20.3559 21.1979 20.0172 21.3705C19.6321 21.5666 19.1281 21.5667 18.12 21.5666H15.6M8.4 21.5666H5.88C4.87191 21.5666 4.36786 21.5666 3.98282 21.3705C3.64413 21.1979 3.36876 20.9225 3.19619 20.5838C3 20.1988 3 19.6947 3 18.6866L3 16.1666')
                fullScreenPath?.setAttribute('stroke', 'white')
                fullScreenPath?.setAttribute('stroke-width', '2')
                fullScreenPath?.setAttribute('stroke-linecap', 'round')
            }
        },
        changeFlagSprite() {
            const flagContainer = document.querySelector('[class*=CountryAgeTag__FlagContainer]')

            if (flagContainer) {
                let newNode = `<img src="/resources/img/icons/flags/${this.countryIso.toLowerCase()}.svg" style="width: 24px; height: auto" alt="country flag">`
                flagContainer.innerHTML = ''
                flagContainer.insertAdjacentHTML('beforeend', newNode)
            }
        },
        setCountryFont() {
            const countryName = document.querySelector('[class*=CountryAgeTag__Container] span')

            if (countryName?.textContent.length >= 15) {
                countryName.style.fontSize = '10px'
            } else if (countryName?.textContent.length > 12) {
                countryName.style.fontSize = '11px'
            }
        },
        showLiveCamLowFundsWarning() {
            this.$root.showModal({
                popupClass: 'popup--sinparty-live',
                title: '<svg class="icon" width="26" height="26"><use href="#exclamation-triangle"/></svg> Low Funds',
                body: `
            <p>Your available funds are too low to complete this action. Please increase available funds to continue.</p>
            <a href="#" onclick="showTopUpBalance();hideModal();" class="gtm_click_btn btn btn--primary">Increase Funds</a>`
            });
        },
    },
}
</script>
<style lang="scss">
@import '@sass/global/variables';

body {
    color: #707070 !important;
    background-color: #141414 !important;

    .is-iphone & {
        padding-bottom: env(safe-area-inset-bottom) !important;
    }
}

.header {
    max-height: 100px;
    transition: max-height 0.5s ease-out;
}

.hqARWq {
    color: rgb(255, 193, 7) !important;
}

[class*=CountryAgeTag__FlagContainer] {
    margin: 0 6px 0 0 !important;
    display: flex !important;
}

[class*=Sidebar__Details] {
    align-items: flex-end !important;
}

#popover_reportcomplaintbutton {
    svg {
        @media(min-width: 947px) {
            fill: #fff !important;
        }
    }
}

[class*=CtaContainer__VideoControlsContainer] {
    opacity: 0;

    svg {
        fill: none !important;
    }
}

[data-ta-locator=VolumeControls__muteButton] {
    svg {
        fill: #fff !important;
    }
}

[data-ta-icon=fullscreenExit] {
    path {
        fill: #fff !important;
    }
}

[class*=ChatDisplay__Container] {
    @media(min-width: 947px) {
        margin-bottom: 8px;
    }
}

[class*=SimilarModels__SimilarModelsContainer] {
    //display: none;
}

[class^=QuickChat__Container] {
    &:hover {
        path {
            fill: white;
            transition: fill 300ms ease;
        }
    }
}

[class^=EmojiPicker__Container], [class^=FontSizeChanger__Container] {
    &:hover {
        path {
            stroke: white;
            transition: fill 300ms ease;
        }
    }
}

[class^=GuestCount__Text], [class^=EmojiPicker__Container], [class^=FontSizeChanger__Container], [class^=QuickChat__Container] {
    svg {
        fill: none !important;
        width: 20px !important;
        height: 20px !important;
        min-width: 20px !important;
        min-height: 20px !important;
    }
}

[class*=ChatTools__Tools] {
    align-items: center !important;
    gap: 7px;
}

[data-ta-locator=ChatTools__send] {
    border-radius: 50px !important;
    background: $moderate_pink !important;
    border: none !important;
    padding: 15px 10px !important;
    cursor: pointer;

    &:hover {
        background: #ee76c9 !important;
    }

    label {
        font-size: 14px !important;
        font-weight: 300 !important;
        color: #fff !important;
    }
}

[class*=ChatForm__InputWrapper] {
    border-radius: 8px !important;
    border-color: $semi_gray !important;

    @media(max-width: 947px) {
        background: #222;
        border-radius: 16px !important;

        [data-ta-locator="ChatForm__PrivateButtonContainer"] {
            background: transparent !important;
            border-right: none !important;
            padding-left: 12px;
            padding-top: 6px;
        }

        [class*=PrivateChat__Container] {
            svg {
                fill: #fff !important;
            }
        }
    }

    input {
        background: transparent !important;
        font-size: 14px !important;
        color: #ececec !important;

        @media(min-width: 947px) {
            padding: 20px 10px !important;
        }

        &::placeholder {
            color: $semi_gray;
        }

        .is-iphone & {
            font-size: 16px !important;
        }
    }
}

[class*=ChatForm__ButtonContainer] {
    @media(max-width: 947px) {
        background: transparent !important;
        svg {
            fill: #fff !important;
        }
    }
}

[class*=MessageList__MessageListScrollWrapper] {
    border-radius: 8px !important;
    background: $dark !important;

    @media(max-width: 947px) {
        background: #222 !important;
        padding: 5px 16px !important;
    }

    [data-ta-locator="GoldMenuButton"] {
        background: linear-gradient(#E7C071, #A07D4E) !important;
        border-radius: 8px !important;
    }
}

[class*=ChatForm__Container] {
    margin-top: 14px !important;
    margin-bottom: 14px !important;

    @media(max-width: 947px) {
        margin-top: 0px !important;
    }
}

[data-ta-locator=InfoMyShows] {
    font-size: 16px !important;

    @media(max-width: 947px) {
        font-weight: 400 !important;
        font-size: initial !important;
        padding-bottom: 8px !important;
        font-size: 20px !important;
    }
}

#header-shape-gradient {
    --color-stop: #E7C071;
    --color-bot: #A07D4E;
}

.QKYCT {
    fill: url(#header-shape-gradient) !important;
}

[class*=Rating__Stars] {
    svg {
        width: 24px !important;
        height: 24px !important;
        max-width: 24px !important;
        max-height: 24px !important;
    }
}

[data-ta-locator*=GoldChatUpsell] {
    background-image: linear-gradient(rgb(75 75 75), rgb(20 20 20)) !important;
    border: 1px solid rgb(37 37 37) !important;
    border-radius: 6px !important;
}

[class^=CountryAgeTag__Container] {
    display: flex !important;
    gap: 0.2rem;
    font-weight: 400 !important;
    align-items: flex-start !important;
}

[class^=Sidebar__Performer-sc] {
    font-weight: 500 !important;
    color: #fff !important;
}

[class^=Message__MessageContainer] {
    span {
        color: #fff !important;
    }

    &:nth-last-child(2) {
        span {
            color: $moderate_pink !important;
        }
    }

}

[data-ta-locator=Sidebar__SidebarContainer] {
    scrollbar-color: $semi_gray transparent !important;
    padding: 16px 11px 8px 11px !important;
    opacity: 0;
    margin-left: 11px !important;

    &::-webkit-scrollbar-thumb {
        background: $semi_gray !important;
    }

    @media(min-width: 947px) and (max-width: $bp_xl) {
        border-radius: 16px 0 0 16px;
    }

    @media(min-width: $bp_xl){
        border-radius: 16px;
    }
}
[data-ta-locator=VideoAndCtaContainer] {
    background: transparent !important;

    @media(min-width: 947px) {
        height: auto !important;
    }
}

[data-ta-locator=CtaContainer__giveGoldButton] {
    background: linear-gradient(#E7C071, #A07D4E) !important;
    border-radius: 50px !important;
    color: #fff !important;
    transition: 0.3s;

    &:hover {
        background: linear-gradient(#ffd88a, #b59264) !important;
    }

    &:disabled {
        background: rgb(66, 66, 66) !important;
    }

    label {
        font-weight: 300;
        font-size: 16px !important;
    }
}

[data-ta-locator=CtaContainer__enterPaidButton] {
    background: $moderate_pink !important;
    border-radius: 50px !important;
    color: #fff !important;
    transition: 0.3s;

    &:hover {
        background: #ee76c9 !important;
    }

    &:disabled {
        background: rgb(66, 66, 66) !important;
    }

    label {
        font-weight: 300;
        font-size: 16px !important;
    }
}

[class*=DesktopPlayerSection], [class*=DesktopRoot__Root-sc] {
    background: transparent !important;
}

[class*=BlackLabelFooter__FooterContainer] {
    display: none;
}

[class^=CtaContainer__Container] {
    background-color: transparent !important;

    @media(min-width: 947px) {
        background-color: $dark !important;
        padding-bottom: 0 !important;
    }

    @media(max-width: $bp_sm) {
        padding: 5px 16px !important;
    }
}

[class*=TabsContainer__Root] {
    background-color: transparent !important;
}

[class*=VideoDisplay__VideoDisplayContainer] {
    @media(min-width: 947px) and (max-width: $bp_xl) {
        border-radius: 0 16px 16px 0;
    }

    @media(min-width: $bp_xl){
        border-radius: 16px;
    }
}

[class^=ActionIconButton__] svg, [class^=VideoDisplay__BottomRightButtons] {
    z-index: 10;
}

.cams-player--top-up-btn {
    width: auto;
    padding: 14px 48px;
    margin-top: 32px;
    font-size: 16px;
}

.popup--sinparty-live {
    background-image: linear-gradient(#d652ae, #240f7d) !important;
}

[data-ta-locator=TabsContainer__Tabs] {
    border: none !important;

    svg {
        fill: transparent !important;
        width: 24px !important;
    }

    button:hover {
        background: transparent !important;
    }

    button[active="1"] path {
        stroke: #fff;
    }
}

span[class^=Tabs-indicator] {
    display: none !important;
}

[data-ta-locator=PhotosGrid__Photo] {
    &::before {
        background-color: transparent !important;
    }

    img {
        border-radius: 10px !important;
        padding: 4px !important;
    }
}

[class*=headers__TwoColumnHeaderLink] {
    font-family: Roboto;
    font-weight: 400;
    font-size: 16px;
    color: #D652AE !important;
}

[class*=TabsContainer__StyledTabContentContainer] {
    padding-top: 5px !important;
    //padding-bottom: 0 !important;
}

[data-ta-locator="PhotoViewerComponents__Image"] {
    height: fit-content !important;
    border-radius: 10px !important;
    object-fit: none !important;
    width: fit-content !important;
}

[data-ta-locator="PhotosStrip__Image"] {
    border-radius: 5px !important;
    height: 88px !important;
    max-width: initial !important;
}

[data-ta-locator="PhotosStrip__Root"] {
    gap: 5px !important;
}

[data-ta-locator="PhotosStrip__SlideLeft"], [data-ta-locator="PhotosStrip__SlideRight"] {
    display: none !important;
}

[data-ta-locator="PhotosStrip__ScrollWrapper"] {
    padding-top: 32px !important;
}

[class*=MobileRoot__MobileRootWrapper] {
    background-color: #141414 !important;
}

[class*=Modal__OverlayDesktop] > div > div {
    z-index: 100000 !important;
}

footer[class*=Footer__FooterBase] {
    display: none;
}

[class*="ToggleChatButton__FullscreenTabsContainer"] {
    border-radius: 16px 16px 0 0 !important;
    padding-top: 8px !important;
    margin-bottom: -10px !important;
}

[class*="InfoContainer__InfoContainerTitle"] {
    font-weight: 500 !important;
    font-family: 'Roboto' !important;
    margin: 5px 0 8px !important;
    color: #fff !important;
}

[data-ta-locator="ChatDisplay__MessageList"] {
    padding: 0 10px 0.5em !important;
}

[class^=Login__LoginContainer] {
    display: var(--login-display);
}

.gyLgcy {
    span {
        color: rgb(255, 193, 7) !important;
    }

    color: rgb(255, 193, 7) !important;
}

.iJGXqM {
    flex: 0 0 38px !important;
    min-height: auto !important;
}

.bVggHN::after {
    box-shadow: none !important;
}

.bjbLcF {
    justify-content: center !important;
}

.jQSTmu {
    flex: none !important;
}

.kzhwvL::before, .hVphYW::before {
    background: transparent !important;
}
button[data-ta-locator="VibeButton"], button[data-ta-locator="GoldMenuButton"], button[data-ta-locator="SpinGoldButton"] {
    -webkit-box-pack: center !important;
    justify-content: center !important;
    width: 54px !important;
    min-width: 48px !important;
    padding: 8px !important;

    label {
        display: none;
    }

    svg {
        margin-left: 0 !important;
        margin-right: 0 !important;
    }
}

[class^=ShowInfoContainer__ButtonContainer], [class^=PremiumInfoContainer__ButtonContainer] {
    flex-flow: inherit !important;
}

[data-ta-locator="GoldShowTopic__Topic"], [class*=GoldShowTopic__Info], [data-ta-locator="GoldShowContainer"] {
    margin: 0 !important;
}
[class*=SimilarModel__ModelContainer] {
    flex: 1 1 calc(50% - 8px) !important;
}
</style>
