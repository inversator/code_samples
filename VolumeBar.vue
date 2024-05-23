<template>
    <div class="volume-bar"
         :class="{'volume-bar--fullscreen' : fullScreen}"
         @mouseenter="hovered=true" @mouseleave="hovered=false"
         v-show="visible || hovered"
         @click="adjustVolume($event)">
        <div ref="volumeSlider"
             class="volume-slider"
             :style="{ '--volume-height': volumeLevel * 100 + '%' }"
        ></div>
    </div>
</template>

<script>
import {SET_ENTITY} from "@/vuejs/store/mutations-types";
import {mapState} from "vuex";

export default {
    name: 'VolumeBar',
    data() {
        return {
            maxVolume: 100,
            hovered: false
        };
    },
    computed: {
        ...mapState('SinCam', ['volumeLevel','fullScreen'])
    },
    props: {
        visible: Boolean
    },
    methods: {
        adjustVolume(event) {

            const boundingRect = this.$refs.volumeSlider.getBoundingClientRect()
            const offsetY = boundingRect.bottom - event.clientY
            const percentage = Math.max(0, Math.min(100, (offsetY / boundingRect.height) * 100))
            const newVolume = Math.round((percentage / 100) * this.maxVolume)

            this[SET_ENTITY]({module: 'SinCam', entity: 'volumeLevel', value: newVolume / 100})
        },
    }
}
</script>

<style scoped lang="scss">
.volume-control {
    position: relative;
}

.volume-icon {
    cursor: pointer;
}

.volume-bar {
    position: absolute;
    bottom: 40px;
    left: 0;
    width: 30px;
    height: 150px;
    background-color: #ffffff1a;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    transition: opacity 0.3s ease;
    border: 10px solid #ffffff1a;
    border-radius: 30px;

    &--fullscreen {
        right: 50px;
        left: auto;
        top: 0px;
    }
}

.volume-bar.active {
    opacity: 1;
}

.volume-slider {
    width: 18px;
    height: 100%;
    background: linear-gradient(to top, #d652ae var(--volume-height), transparent var(--volume-height));
}
</style>
