<template>
    <div v-if="isActive && isOnline && !streamDataIsLoading && !modelInPrivate"
         class="sincam-player"
         :class="{'sincam-player--fullscreen' : fullScreen}">

        <sin-cam-player-hls :volume="volume" />

        <div class="sincam-player__top-left">
            <div v-if="fullScreen" class="sincam-player__top-info-block">

                <!-- Model name and follow btn -->
                <div class="cam-model__top--left">
                    <span class="sincam-player__avatar">
                    <ImageStatic
                        v-if="contentCreatorData.picture_url"
                        :source="{ picture_url: contentCreatorData.picture_url }"
                        :size="80"
                        :width="40"
                        :height="40"
                        cover
                    />
                    </span>
                    <span class="sincam-model__title">{{ contentCreatorData.title }}
                        <span>
                            <svg v-if="creatorsTickHtml" width="16" height="16"><use :href="creatorsTickHtml"/></svg>
                        </span>
                    </span>
                    <button-follow
                        class="creator-panel__cta"
                        :user-is-follower='userIsFollower'
                        :creator-user-id='contentCreatorData.user_id'
                    ></button-follow>
                </div>
            </div>

            <!-- Live badge -->
            <div class="sincam-player__top-info-block">
                <div class="sincam-player__live-badge">
                    <svg width="13" height="13">
                        <use href="#camera"/>
                    </svg>
                    {{ $t('header.live').toUpperCase() }}
                </div>
                <div class="sincam-player__viwer-count">
                    {{ viewerCount }} {{ $t(viewerCount == 1 ? 'sincam.viewer' : 'sincam.viewers').toLowerCase() }}
                </div>
            </div>

            <div v-if="showSeconds" class="sincam-player__timer">{{ formattedShowTime }}</div>
        </div>

        <!-- Top right block -->
        <div class="sincam-player__top-right" :class="{'sincam-player__top-right--fullscreen': fullScreen}">
            <sin-cam-settings v-if="fullScreen"></sin-cam-settings>
            <span v-if="!fullScreen && showTitle" v-text="showTitle"/>

            <template v-if="!['wheel'].includes(chatActiveTab) && !pollsIsActive">
                <template v-if="!fullScreen">
                    <div class="sincam-player__wheel-icon" @click="showWheel()">
                        <svg width="30" height="30">
                            <use href="#wheel"/>
                        </svg>
                    </div>
                    <div class="sincam-player__polls-icon" @click="showPolls()">
                        <svg width="30" height="30">
                            <use href="#polls"/>
                        </svg>
                    </div>
                </template>
            </template>
            <sin-cam-polls v-if="pollsIsActive"></sin-cam-polls>
        </div>

        <div class="sincam-player__center-right">
            <sin-cam-wheel></sin-cam-wheel>
        </div>

        <!-- Left bottom block -->
        <div class="sincam-player__bottom-left">
            <div v-if="isExclusive" class="sincam-player__user-camera"></div>
            <sin-cam-complete-goal v-if="showCompleteGoal"></sin-cam-complete-goal>
            <sin-cam-goal v-if="!fullScreen"></sin-cam-goal>
            <sin-cam-tip-panel v-else class="sincam-player__tip-panel-wrapper"></sin-cam-tip-panel>
        </div>

        <!-- Right bottom block -->
        <div class="sincam-player__bottom-right">
            <sin-cam-settings v-if="!fullScreen"></sin-cam-settings>
            <SinCamChat v-else class="sincam-model__chat"/>
        </div>

        <!-- Balance counter -->
        <div class="sincam-player__balance-counter" style="display: none">
            <div class="sincam-player__counter-block">
                <span>2:05</span>
                <span>{{ $t('sincam.left_running_of_tokens') }}</span>
            </div>
            <div class="sincam-player__counter-top-up-block">
                <span @click="buyTokens()">{{ $t('sincam.buy_tokens') }}</span>
                <svg width="20" height="20">
                    <use href="#cross"/>
                </svg>
            </div>
        </div>
    </div>

    <!-- Stub for unavailable condition -->
    <sin-cam-player-stub v-else></sin-cam-player-stub>
</template>
<script>
import {mapActions, mapGetters, mapMutations, mapState} from "vuex";
import SinCamGoal from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamGoal.vue";
import SinCamSettings from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamSettings.vue";
import SinCamPlayerStub from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamPlayerStub.vue";
import ButtonFollow from "@/vuejs/components/Buttons/ButtonFollow.vue";
import ImageStatic from "@/vuejs/components/ImageStatic.vue";
import SinCamTipPanel from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamTipPanel.vue";
import SinCamChat from "@/vuejs/components/LiveCams/SinCam/SInCamChat/SinCamChat.vue";
import SinCamCompleteGoal from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamCompleteGoal.vue";
import SinCamWheel from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamWheel.vue";
import {SET_IS_FALSE, SET_IS_TRUE} from "@/vuejs/store/mutations-types";
import SinCamPolls from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamPolls.vue";
import VideoPlayerHls from "@/vuejs/components/Video/VideoPlayerHls.vue";
import SinCamPlayerHls from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/SinCamPlayerHls.vue";

export default {
    name: 'SinCamPlayer',
    components: {
        SinCamPlayerHls,
        VideoPlayerHls,
        SinCamPolls,
        SinCamWheel,
        SinCamCompleteGoal,
        SinCamChat, SinCamTipPanel, ImageStatic, ButtonFollow, SinCamPlayerStub, SinCamSettings, SinCamGoal
    },
    data() {
        return {}
    },
    watch: {
        fullScreen(value) {
            if (value) {
                document.querySelector('.header')?.classList.add('bar--hide')
                document.querySelector('.footer-mobile')?.classList.add('bar--hide')
                document.querySelector('.footer')?.classList.add('bar--hide')
                zE('messenger', 'hide');
            } else {
                document.querySelector('.header')?.classList.remove('bar--hide')
                document.querySelector('.footer-mobile')?.classList.remove('bar--hide')
                document.querySelector('.footer')?.classList.remove('bar--hide')
                zE('messenger', 'show');
            }
        }
    },
    computed: {
        ...mapState('Content', ['contentCreatorData', 'userIsFollower']),
        ...mapState('SinCam', ['showSeconds', 'isActive', 'stream', 'isOnline', 'modelInPrivate', 'showTitle', 'fullScreen', 'isPrivate', 'isExclusive', 'showCompleteGoal', 'pollsIsActive', 'volume', 'streamDataIsLoading']),
        ...mapState('SinCamChat', {chatActiveTab: 'activeTab'}),
        ...mapGetters('Content', ['creatorsTickHtml']),
        ...mapGetters('SinCamChat', ['viewerCount']),

        formattedShowTime() {
            const minutes = Math.floor(this.showSeconds / 60)
            const seconds = this.showSeconds % 60
            const formattedSeconds = seconds < 10 ? '0' + seconds : seconds

            return `${minutes}:${formattedSeconds}`
        }
    },
    methods: {
        ...mapMutations('SinCam', [SET_IS_TRUE, SET_IS_FALSE]),
        ...mapMutations('SinCamChat', {setChatTab: 'setActiveTab'}),
        ...mapActions('SinCam', ['getStream']),
        buyTokens() {
            this.$showModal('ModalTopUpBalance', {justTopUp: true, source: 'sincam'})
        },
        showWheel() {
            this.setChatTab('wheel')
        },
        showPolls() {
            this.pollsIsActive ? this[SET_IS_FALSE]('pollsIsActive') : this[SET_IS_TRUE]('pollsIsActive')
        },
    },
    mounted() {
        this.getStream()
    }
}
</script>
<style lang="scss">
@import "@sass/live-cam-pages/sincam/variables";
@import "@sass/global/variables";

.creator-panel {
    &__cta {
        position: relative;

        display: flex;
        flex: none;
        align-items: center;
        max-width: min-content;
        height: 1.6875rem;
        padding: 0 0.875rem;

        font-size: 0.875rem;
        line-height: 1;
        color: #ffffff;

        background-color: transparent;
        border: 2px solid #d9d9d9;
        border-radius: 1000px;

        transition: none;

        &:hover,
        &:focus,
        &.active {
            border-top-color: $moderate_pink;
            border-right-color: #ed4f6e;
            border-bottom-color: $bright_red;
            border-left-color: #ed4f6e;

            @include acid-text;

            span {
                -webkit-text-fill-color: #ffffff;
            }
        }

        &.active:hover span {
            -webkit-text-fill-color: transparent;
        }

        @media (min-width: $bp_lg) {
            height: 2.5rem;
            padding: 0 1.375rem;

            font-size: 1em;

            border-width: 2px;
        }
    }
}

.bar--hide {
    max-height: 0 !important;
    overflow: hidden;
    margin: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
}

.bar--hide .header__main {
    display: none;
}

.sincam-player {
    aspect-ratio: 16 / 9;
    grid-area: 2 / 1 / 3 / 2;

    background: url(/resources/img/frontend/live-cam-pages/sin-cam-offline.png), no-repeat center center;
    background-size: cover;
    border-radius: 1.9375rem;

    position: relative;

    overflow: hidden;

    &__wheel-icon, &__polls-icon {
        padding: 0.625rem;
        border-radius: 1rem;
        background: rgba(29, 29, 29, 0.80);
        cursor: pointer;

        transition: all .3s ease-in;
        z-index: 10;

        &:hover {
            background: rgba(29, 29, 29, 0.9);
        }
    }

    &__balance-counter {
        display: flex;
        //width: 58.125rem;
        height: 3.75rem;
        padding: 0 1.5rem;
        justify-content: space-between;
        align-items: center;
        border-radius: 0 0 2rem 2rem;
        border: 1px solid $light_warm_gray;
        background: $gray_medium;

        bottom: 0;
        position: absolute;
        width: 100%;
    }

    &__counter-block {
        display: flex;
        align-items: center;
        gap: 0.5rem;

        span {
            color: $gray_dirty_moon;
            font-size: 1rem;
            font-weight: 500;

            &:first-child {
                color: $moderate_pink;
                font-size: 1.5rem;
                font-weight: 700;
            }
        }
    }

    &__counter-top-up-block {
        display: flex;
        align-items: center;
        gap: 1.5rem;

        span {
            cursor: pointer;

            display: flex;
            width: 7.5rem;
            height: 2.1875rem;
            padding: 0.625rem;
            justify-content: center;
            align-items: center;

            border-radius: 22.5rem;
            background: $primary_green;
        }

        svg {
            color: #aaa;
            cursor: pointer;
        }
    }

    &__user-camera {
        background: url(/resources/img/frontend/live-cam-pages/sincam-user-camera-preview.png), no-repeat center center;
        background-size: cover;
        border-radius: 1rem;
        width: 9.375rem;
        height: 7.01575rem;
        margin-bottom: 0.5rem;
    }

    &__timer {
        display: flex;
        padding: 0.25rem 0.625rem;
        justify-content: center;
        align-items: center;
        width: fit-content;

        border-radius: 0.9375rem;
        background: rgba(29, 29, 29, 0.40);

        color: #fff;

        font-size: 1rem;
        font-weight: 400;
    }

    &__tip-panel-wrapper {
        display: flex;
        width: 63.3125rem;
        padding: 0.5rem 1rem 0.5rem 0.5rem;
        justify-content: space-between;
        align-items: flex-start;

        border-radius: 1rem;
        background: rgba(29, 29, 29, 0.70);
        backdrop-filter: blur(12.5px);
    }

    &__avatar {
        img {
            border-radius: 50%;
        }
    }

    &--fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        z-index: 21;
        border-radius: 0;

        .sincam-model__title {
            font-size: 1.375rem;
        }
    }

    &__live-badge {
        display: flex;
        height: 1.42569rem;
        padding: 0.21931rem 0.43869rem;
        align-items: center;
        gap: 0.1065rem;

        border-radius: 19.16519rem;
        background: $primary_green;

        font-size: 0.76769rem;
        font-style: normal;
        font-weight: 400;
    }

    &__top-left {
        position: absolute;
        top: 1.37rem;
        left: 1.87rem;

        font-size: 0.63363rem;
        color: #fff;

        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    &__top-info-block {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    &__top-right {
        position: absolute;
        top: 1.7rem;
        right: 1.4rem;

        display: flex;

        font-size: 0.63363rem;
        color: #fff;
        gap: 0.5rem;

        align-items: flex-end;
        flex-direction: column;

        z-index: 10;

        &--fullscreen {
            align-items: center;
        }
    }

    &__center-right {
        position: absolute;
        right: 0;
        top: 0;
        height: 100%;
        display: flex;
        align-items: center;
        z-index: 5;
    }

    &__bottom-left {
        position: absolute;
        left: 1.4375rem;
        bottom: 1.4rem;
        z-index: 10;
    }

    &__bottom-right {
        position: absolute;
        right: 1.4rem;
        bottom: 1.4rem;
        z-index: 10;
    }
}
</style>
