<template>
    <div v-if="goalsEnabled && goal" class="sincam-goal" :style="goalBackground">
        <div class="sincam-goal__left-block">
            <svg width="12" height="12">
                <use href="#target"/>
            </svg>
            <span class="sincam-goal__title">{{ $t('sincam.goal') }}</span>
            <span class="sincam-goal__token">{{ goal.tokens }}tk</span>
            <span>{{ goal.description }}</span>
            <span v-if="tipMenuEnabled" class="sincam-goal__link" @click="showTipMenu()">
                {{ $t('sincam.see_tip_menu') }}
            </span>
        </div>

        <div class="sincam-goal__right-block">{{ goal.percentage }}%</div>
    </div>
</template>
<script>
import {mapGetters, mapState} from "vuex";
import {mapMutations} from "@modules/vuex";

export default {
    name: 'SinCamGoal',
    data() {
        return {
        }
    },
    computed: {
        ...mapState('SinCam', ['stream']),
        ...mapGetters('SinCam', ['goal']),
        goalBackground() {
            const endColorPercentage = this.goal?.percentage + 3.83
            return `background: linear-gradient(90deg, rgba(33, 191, 58, 0.54) ${this.goal?.percentage}%, rgba(52, 212, 87, 0) ${endColorPercentage}%), #222;`
        },
        tipMenuEnabled() {
            return this.stream?.tipsEnabled
        },
        goalsEnabled() {
            return this.stream?.goalsEnabled
        }
    },
    methods: {
        ...mapMutations('SinCamChat', ['setActiveTab']),
        showTipMenu() {
            this.setActiveTab('tips')
        }
    }
}
</script>
<style lang="scss">
@import '@sass/live-cam-pages/sincam/variables';

.sincam-goal {
    &__link {
        color: $light_warm_gray;
        cursor: pointer;
    }

    &__right-block {
        color: $primary-green;
        font-size: 0.625rem;
        font-weight: 700;
    }

    &__left-block {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    &__title {
        color: #FFF;
        font-size: 0.75rem;
        font-style: normal;
        font-weight: 700;
    }

    &__token {
        color: $primary_green;
        font-weight: 600;
    }

    font-size: 0.625rem;
    font-style: normal;
    font-weight: 400;

    display: flex;
    width: 23.875rem;
    height: 1.875rem;
    padding: 0.25rem 0.5625rem;
    justify-content: space-between;
    align-items: center;

    border-radius: 22.5rem;
}
</style>
