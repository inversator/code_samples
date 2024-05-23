<template>
    <div class="sincam-model__tip-panel">
        <div v-if="isActive && isOnline && !isPrivate" class="sincam-model__tip-stats">
            <div class="sincam-model__tip-stats-block">
                <span>{{ $t('sincam.tip_received') }}</span>
                <span v-if="!tipsIsLoading">{{ allTips }}</span>
                <span v-else> - </span>
            </div>
            <div class="sincam-model__tip-stats-block">
                <span>{{ $t('sincam.highest_tip') }}</span>
                <span v-if="!tipsIsLoading">{{ maxTip }}</span>
                <span v-else> - </span>
            </div>
            <div class="sincam-model__tip-stats-block">
                <span>{{ $t('sincam.latest_tip') }}</span>
                <span v-if="!tipsIsLoading">{{ latestTip }}</span>
                <span v-else> - </span>
            </div>
        </div>

        <div v-if="isPrivate && isOnline && isActive" class="sincam-model__show-btn-panel">
            <div v-if="!modelInPrivate" @click="requestShowStop()" class="sincam-model__end-private">
                {{ $t('sincam.end_private') }}
            </div>
            <div v-if="isExclusive" class="sincam-model__camera-settings">{{ $t('sincam.enable_camera_mic') }}</div>
        </div>

        <div v-if="!isOnline || !isActive" class="sincam-model__tip-stats sincam-model__tip-stats--offline">
            <div >
                <span>{{ $t('sincam.my_balance') }}:</span>
                <span v-text="formatedBalance"/>
            </div>
        </div>

        <div class="sincam-model__tip-btn" @click="showTipPopup">
            {{ $t('actions.tip') }}
        </div>

        <div class="sincam-model__balance"
             :class="{'sincam-model__balance--offline' : !isOnline || !isActive || isGuest}">
            <div v-if="isActive && isOnline && (!modelInPrivate || isPrivate) && !isGuest">
                <span>{{ $t('sincam.my_balance') }}:</span>
                <span v-text="formatedBalance"/>
            </div>
            <div>
                <span v-if="isActive && isOnline && !modelInPrivate && !isPrivate && !isGuest && privateShow"
                      @click="goPrivate()"
                      v-text="$t('sincam.go_private')"/>

                <span @click="buyTokens()" v-text="$t('sincam.buy_more_tokens')"/>
            </div>
        </div>
    </div>
</template>
<script>
import {mapActions, mapMutations, mapState} from "vuex";
import {mapGetters} from "@modules/vuex";
import {SET_IS_TRUE} from "@/vuejs/store/mutations-types";

export default {
    name: 'SinCamTipPanel',
    data() {
        return {}
    },
    computed: {
        ...mapGetters('Auth', ['userBalance', 'isGuest']),
        ...mapGetters('SinCam', ['isSpy', 'isPrivate', 'goal', 'privateShow', 'spyShow', 'exclusiveShow']),
        ...mapState('SinCam', ['showSeconds', 'isActive', 'isOnline', 'modelInPrivate', 'privateRequestSeconds', 'isExclusive', 'tips', 'tipsIsLoading', 'stream', 'requestStatus']),

        formatedBalance() {
            const balance = this.userBalance < 0 ? '–' : '' + this.$formatFinancialRoundable(this.userBalance)
            return balance + ' ' + (this.userBalance > 1 ? this.$t('sincam.tokens') : this.$t('sincam.token'))
        },
        maxTip() {
            const tip = this.tips?.highest

            if (tip && tip.amount) {
                return `${tip.username}  (${tip.amount})`
            }

            return '-'
        },
        latestTip() {
            const tip = this.tips?.latest

            if (tip && tip.amount) {
                return `${tip.username}  (${tip.amount})`
            }

            return '-'
        },
        allTips() {
            const tip = this.tips?.all

            if (tip && tip.amount) {

                return this.stream?.goalsEnabled && this.goal
                    ? `${tip.amount}/${this.goal?.tokens}`
                    : tip.amount
            }

            return '-'
        }
    },
    watch: {
        showSeconds(seconds) {
            if (seconds && (seconds % 60 === 0)) {
                if (this.isSpy) {
                    this.balancePayment(this.spyShow?.tokens)
                } else {
                    this.balancePayment(this.privateShow?.tokens)
                }
            }
        }
    },
    methods: {
        ...mapActions('SinCam', ['stopPrivate', 'balancePayment']),
        ...mapMutations('SinCam', [SET_IS_TRUE]),
        requestShowStop() {
            this[SET_IS_TRUE]('isStopped')
            this.stopPrivate()
        },
        goPrivate() {
            if (this.privateRequestSeconds) {
                if (this.requestStatus === 'pending') {
                    this.$showModal('ModalSinCam', {
                        title: this.$t('sincam.private_show_requested'),
                        body: this.$t('sincam.waiting_creator_respond'),
                        type: 'privateRequested'
                    })
                } else {
                    this.$showModal('ModalSinCam', {
                        title: this.$t('sincam.private_show_requested'),
                        body: this.$t('sincam.creator_declined_private') + ' ' + this.$t('sincam.private_request_again_in'),
                        type: 'privateDeclined'
                    })
                }
            } else {
                this.$showModal('ModalSinCamPrivateRequest', {})
            }
        },
        showTipPopup() {
            this.$showModal('ModalSinCamTips', {})
        },
        buyTokens() {
            this.$showModal('ModalTopUpBalance', {justTopUp: true, source: 'sincam'})
        },
    },
    beforeDestroy() {
        console.log('Sin cam tip panel destroy')
    }
}
</script>
<style scoped lang="scss">
@import "@sass/live-cam-pages/sincam/variables";
@import "@sass/global/variables";

.sincam-model {
    &__show-btn-panel {
        display: flex;
        width: 21.75rem;
        align-items: center;
        gap: 1.5rem;
    }

    &__camera-settings {
        display: flex;
        height: 2.1875rem;
        padding: 0.625rem;
        justify-content: center;
        align-items: center;
        gap: 0.625rem;
        flex: 1 0 0;

        border-radius: 22.5rem;
        background: $light_warm_gray;

        cursor: pointer;
    }

    &__end-private {
        display: flex;
        width: 8.375rem;
        height: 2.1875rem;
        padding: 0.625rem;
        justify-content: center;
        align-items: center;
        gap: 0.625rem;
        flex-shrink: 0;

        border-radius: 22.5rem;
        border: 1px solid $gray_light_2;
        background: $gray_gray;
        color: $gray_dirty_moon;

        cursor: pointer;
    }

    &__balance {
        display: flex;
        justify-content: space-between;
        justify-self: flex-end;
        align-items: center;
        gap: 1rem;
        width: 100%;

        &--offline {
            display: flex;
            flex-direction: column;
            align-items: flex-end;

            justify-content: center;

            span {
                color: $gray_dirty_moon !important;

                font-size: 0.75rem !important;
                font-weight: 400 !important;
                transition: all .3s;

                &:hover {
                    color: #fff !important;
                }
            }
        }

        div {
            &:first-child {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 0.5rem;
                color: $light_warm_gray;

                span {
                    &:first-child {
                        font-size: 0.625rem;
                        font-weight: 400;
                    }

                    &:last-child {
                        font-size: 1.125rem;
                        font-weight: 500;
                        color: #fff;
                    }
                }
            }

            &:last-child {
                display: flex;
                flex-direction: column;
                align-items: flex-end;
                color: $gray_dirty_moon;

                span {
                    font-size: 0.75rem;
                    font-weight: 400;
                    cursor: pointer;
                    transition: all .3s;

                    &:hover {
                        color: #fff;
                    }
                }
            }
        }
    }

    &__tip-btn {
        display: flex;
        width: 7.5rem;
        height: 2.1875rem;
        padding: 0.625rem;
        justify-content: center;
        align-items: center;
        gap: 0.625rem;

        border-radius: 22.5rem;
        background: $primary_green;
        cursor: pointer;
    }

    &__tip-stats-block {
        display: flex;
        width: 6.25rem;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;

        color: $light_gray;
        font-size: 0.625rem;
        font-weight: 400;

        span:first-child {
            color: #fff;
            font-weight: 700;
        }
    }

    &__tip-stats {
        display: flex;
        padding: 0.25rem 0.5rem;
        align-items: flex-start;

        gap: 1rem;

        border-radius: 22.5rem;
        background: $gray_medium;

        &--offline {
            background: none;
            justify-self: baseline;

            div {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 0.5rem;
                color: #808080;
            }

            span {
                &:first-child {
                    font-size: 0.625rem;
                    font-weight: 400;
                }

                &:last-child {
                    font-size: 1.125rem;
                    font-weight: 500;
                    color: #fff;
                }
            }
        }
    }

    &__tip-panel {
        grid-area: 3 / 1 / 4 / 2;

        display: grid;
        align-self: stretch;
        grid: auto/22rem auto 22rem;
        justify-items: center;
        align-items: center;
    }
}
</style>
