<template>
    <div class="sincam-settings" :class="{'sincam-settings--fullscreen' : fullScreen}">
        <span class="sincam-settings__icon"
              :class="{ 'sincam-settings__icon--active': false }"
              @mouseenter="isVolumeBarVisible = true"
              @mouseleave="hideVolumeBar()"
        >
            <svg @click="toggleVolume" width="34" height="32">
                   <use :href="!volume ? '#volume' : '#volume-off-icon'"/>
            </svg>
            <volume-bar :visible="isVolumeBarVisible"></volume-bar>
        </span>
        <span ref="settingIcon" class="sincam-settings__icon" :class="{ 'sincam-settings__icon--active': false }">
            <svg width="32" height="32">
                <use href="#settings"/>
            </svg>
        </span>
        <span class="sincam-settings__icon" :class="{ 'sincam-settings__icon--active': false }"
              @click="toggleFullscreen()">
            <svg width="32" height="32">
                   <use :href="!fullScreen ? '#fullscreen' : '#exit-fullscreen'"/>
            </svg>
        </span>
        <div ref="settingMenu" class="sincam-settings__menu"
             :class="{'sincam-settings__menu--fullscreen' : fullScreen}">
            <h3>{{ $t('sincam.video_appearance') }}</h3>
            <ul v-if="qualityLevels.length">
                <!-- Auto resolution -->
                <li class="sincam-settings__menu-item"
                    :class="{'sincam-settings__menu-item--active' : null === activeQualityLevel || activeQualityLevel === -1}"
                    @click="setVideoResolution(-1)"
                    v-text="'Auto'"/>

                <!-- Resolution items -->
                <li v-for="(resolution, index) in qualityLevels"
                    class="sincam-settings__menu-item"
                    :class="{'sincam-settings__menu-item--active' : index === activeQualityLevel}"
                    @click="setVideoResolution(index)">
                    {{ resolution.height }}p
                </li>
                <li class="sincam-settings__menu-item" @click="reportHandler">{{ $t('sincam.report_video_issue') }}</li>
            </ul>
        </div>
    </div>
</template>
<script>
import {mapMutations, mapState} from "vuex"
import {SET_ENTITY, SET_IS_FALSE, SET_IS_TRUE} from '@/vuejs/store/mutations-types'
import VolumeBar from "@/vuejs/components/LiveCams/SinCam/SinCamPlayer/VolumeBar.vue";

export default {
    name: 'SinCamSettings',
    components: {VolumeBar},
    data() {
        return {
            isVolumeBarVisible: false,
            menuDisplayTimer: null,
            showSettings: false,
            allowedVideoResolutions: ['1080p', '720p', '480p', '240p', '160p']
        }
    },
    computed: {
        ...mapState('Content', ['contentCreatorData']),
        ...mapState('SinCam', ['volume', 'fullScreen', 'activeQualityLevel', 'qualityLevels']),

        activeVideoResolution() {
            return this.qualityLevels.length ? this.qualityLevels[this.activeQualityLevel] : null
        }
    },
    methods: {
        ...mapMutations('SinCam', [SET_IS_TRUE, SET_IS_FALSE]),
        hideVolumeBar() {
            setTimeout(() => this.isVolumeBarVisible = false, 1000)
        },
        setVideoResolution(index) {
            this[SET_ENTITY]({module: 'SinCam', entity: 'activeQualityLevel', value: index})
        },
        reportHandler() {
            this.$showModal('ModalSinCamReport', {
                userId: this.contentCreatorData.user_id,
                objectHash: this.contentCreatorData.model_hash,
                objectType: 'sincam'
            })
        },
        toggleVolume() {
            this.volume ? this[SET_IS_FALSE]('volume') : this[SET_IS_TRUE]('volume')
            this[SET_ENTITY]({module: 'SinCam', entity: 'volumeLevel', value: 0})
        },
        toggleFullscreen() {
            this.fullScreen ? this[SET_IS_FALSE]('fullScreen') : this[SET_IS_TRUE]('fullScreen')
        },
        setMenuListeners() {
            if (this.$refs.settingIcon && this.$refs.settingMenu) {

                // Icon
                this.$refs.settingIcon.addEventListener('mouseenter', this.showMenu)
                this.$refs.settingIcon.addEventListener('mouseleave', this.hideMenu)

                // Menu
                this.$refs.settingMenu.addEventListener('mouseenter', () => clearTimeout(this.menuDisplayTimer))
                this.$refs.settingMenu.addEventListener('mouseleave', this.immediateHideMenu)
                this.$refs.settingMenu.addEventListener('click', this.immediateHideMenu)

            } else {
                console.error('Setting nods menu/icon not found', this.$refs.settingMenu, this.$refs.settingIcon)
            }
        },
        showMenu() {
            clearTimeout(this.menuDisplayTimer)
            this.$refs.settingMenu.style.display = 'flex'
        },
        hideMenu() {
            this.menuDisplayTimer = setTimeout(() => {
                this.$refs.settingMenu.style.display = 'none'
            }, 2000)
        },
        immediateHideMenu() {
            this.$refs.settingMenu.style.display = 'none'
        }
    },
    mounted() {
        setTimeout(this.setMenuListeners, 500)
    },
    beforeDestroy() {
        if (this.$refs.settingIcon && this.$refs.settingMenu) {
            // Icon
            this.$refs.settingIcon.removeEventListener('mouseenter', this.showMenu)
            this.$refs.settingIcon.removeEventListener('mouseleave', this.hideMenu)

            // Menu
            this.$refs.settingMenu.removeEventListener('mouseenter', () => clearTimeout(this.menuDisplayTimer))
            this.$refs.settingMenu.removeEventListener('mouseleave', this.immediateHideMenu)
            this.$refs.settingMenu.removeEventListener('click', this.immediateHideMenu)

        } else {
            console.error('Setting nods menu/icon not found', this.$refs.settingMenu, this.$refs.settingIcon)
        }
    },
}
</script>
<style lang="scss">
@import '@sass/live-cam-pages/sincam/variables';
@import '@sass/global/variables';

.sincam-settings {
    &--fullscreen {
        flex-direction: column;
        align-self: end;
    }

    position: relative;

    &__menu-item {
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
        align-self: stretch;

        color: $light_warm_gray;
        cursor: pointer;

        &:hover {
            color: #fff;
        }

        &--active {
            color: #fff;
        }
    }

    &__menu {
        &--fullscreen {
            right: 3rem;
            top: 0;
        }

        height: fit-content;
        position: absolute;
        right: -0.7rem;
        bottom: 3rem;

        display: none;
        width: 11.25rem;
        padding: 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;

        border-radius: 1.5rem;
        background: $dark_gray;

        box-shadow: 0px 5px 14px 0px rgba(0, 0, 0, 0.60);
        z-index: 11;

        h3 {
            align-self: stretch;
            color: #FFF;
            font-size: 1rem;
            font-weight: 700;
        }

        ul {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
            align-self: stretch;
        }
    }

    &__icon {
        color: #fff;
        fill: #fff;

        &:hover, &--active {
            color: $moderate_pink;
            fill: $moderate_pink;
        }

        cursor: pointer;
    }

    &__settings {
    }

    &__fullscreen {
    }

    display: flex;
    justify-content: flex-end;
    align-items: flex-start;
    gap: 2rem;
}
</style>
