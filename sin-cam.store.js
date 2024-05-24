import {
    SET_IS_TRUE,
    SET_IS_FALSE, SET_ENTITY, INCREMENT_ENTITY,
} from '@/vuejs/store/mutations-types'
import {
    requestStopPrivate,
    setStreamData,
    checkStreamUrl,
    getTipStats,
    getTipMenu,
    getGoals,
    requestShow, requestSpy
} from '@/vuejs/api/apiStream'

import {balancePayment} from "@/vuejs/api/apiPayments"

const module = 'SinCam'
export default {
    namespaced: true,
    state: {
        userMessage: null,
        lastError: null,
        test: true,

        tabs: ['bio', 'videos_and_photos', 'feed'],
        activeTab: 'bio',

        streamDataIsLoading: false,

        isActive: true,
        isOnline: false,
        isStopped: false,
        spyIsActive: false,

        connectAttempt: 0,

        stream: {
            id: 0,
            isActive: false,

            goalsEnabled: 0,
            tipsEnabled: 0,

            url: null,
            duration: 0,
            thumbnail: null,

            subscriberId: 'public',
            subscriberCode: null,
        },

        showTitle: 'Show title long description',

        prices: null,
        modelInPrivate: false,
        isExclusive: false,

        showTimer: null,
        privateSeconds: 0,
        privateRequestTimer: null,
        privateRequestSeconds: 0,
        requestWaitingTime: 10 * 60,
        requestStatus: null,
        privateUserId: null,

        showSeconds: 0,

        tips: null,
        tipsIsLoading: false,
        tipMenu: null,

        goals: null,
        showCompleteGoal: false,

        qualityLevels: [],
        activeQualityLevel: null,

        listLink: null,
        couplesLink: null,

        similarModels: null,
        socialLinks: null,
        content: null,

        tipPredefinedAmount: [20, 50, 100, 200, 500],

        volume: false,
        volumeLevel: 0,

        fullScreen: false,

        pollsIsActive: false,

        wheelDegreeStep: 0,
        wheelPrizeIndex: null,
        wheelSectors: 8,
        wheelMenu: [
            {title: 'Play Nipple'},
            {title: 'Show boob'},
            {title: 'Suck Dildo'},
            {title: 'Ball gag'},
            {title: 'Oil'},
            {title: 'Suck Dildo'},
            {title: 'Show boob'},
            {title: 'Oil'},
        ],
    },
    getters: {
        currentShowType(state, getters) {
            if (getters.isPrivate) {
                if (getters.isSpy) {
                    return 'spy-show'
                }

                return 'private-show'
            }

            return null
        },
        privateShow(state) {
            return state.prices?.find(el => el.type === 'private')
        },
        spyShow(state) {
            return state.prices?.find(el => el.type === 'spy')
        },
        exclusiveShow(state) {
            return state.prices?.find(el => el.type === 'exclusive')
        },
        isPrivate(state) {
            return (state.stream.type === 'private') && (state.stream.subscriberId && state.stream.subscriberId !== 'public')
        },
        isMyShow(state, getters, rootState) {
            return getters.isPrivate && (state.privateUserId === rootState.Auth.user.user_id)
        },
        isSpy(state) {
            return state.spyIsActive
        },
        getFullStreamUrl(state) {
            const encodedSubscriberId = encodeURIComponent(state.stream?.subscriberId);
            const encodedSubscriberCode = encodeURIComponent(state.stream?.subscriberCode);

            return state.stream.url + '?subscriberId=' + encodedSubscriberId + '&subscriberCode=' + encodedSubscriberCode;
        },
        goal(state) {
            if (state.goals && typeof state.goals[0] !== 'undefined') {
                let collectedTips = state.tips?.all?.amount ?? 0

                for (let i = 0; i < state.goals.length; i++) {
                    let goal = state.goals[i]

                    if (collectedTips < goal.tokens) {
                        let percentage = (collectedTips / goal.tokens) * 100
                        percentage = Math.round(percentage)

                        return {
                            id: goal.id,
                            description: goal.description,
                            tokens: goal.tokens,
                            percentage: percentage,
                            leftToComplete: goal.tokens - collectedTips
                        };
                    }

                    collectedTips -= goal.tokens;
                }
            }

            return null
        },
        getHoursAgo(dateTimeStr) {
            const pastDate = new Date(dateTimeStr)
            const now = new Date()
            const diffInMs = now - pastDate
            const diffInHours = diffInMs / (1000 * 60 * 60)
            return `${Math.floor(diffInHours)} ${this.$t('sincam.hours_ago')}`
        }
    },

    mutations: {
        [SET_IS_TRUE](state, entity) {
            state[entity] = true
        },
        [SET_IS_FALSE](state, entity) {
            state[entity] = false
        },
        [INCREMENT_ENTITY](state, entity) {
            state[entity]++
        },
        setActiveTab(state, tab) {
            state.activeTab = tab
        },
        setPrivateRequestTimeout(state) {
            state.privateRequestSeconds = state.requestWaitingTime
        },
        setPrivateRequestTimer(state) {
            if (state.privateRequestTimer)
                clearInterval(state.privateRequestTimer)

            state.privateRequestTimer = setInterval(() => {
                state.privateRequestSeconds--

                if (!state.privateRequestSeconds) {
                    clearInterval(state.privateRequestTimer)
                    state.privateRequestTimer = null
                }
            }, 1000)
        },
        clearPrivateRequestTimer(state) {
            if (state.privateRequestTimer)
                clearInterval(state.privateRequestTimer)

            state.privateRequestSeconds = 0
        },
        setShowTimer(state) {
            if (state.showTimer)
                clearInterval(state.showTimer)

            state.showTimer = setInterval(() => {
                state.showSeconds++
            }, 1000)
        },
        clearShowTimer(state) {
            if (state.showTimer) {
                console.info('Clearing show timer')
                clearInterval(state.showTimer)
            } else {
                console.info('Show timer not found')
            }

            state.showSeconds = 0
        },
        setWheelDegreeStep(state) {
            const additionalDegrees = Math.floor(Math.random() * (360 - 1)) + 1

            // Additional degrees for the wheel fast rotation
            const fullRotations = 360 * 5
            const totalDegrees = fullRotations + additionalDegrees

            state.wheelDegreeStep += totalDegrees
        },
        setWheelPrize(state) {
            const normalizedDegrees = state.wheelDegreeStep % 360
            const degreesPerSector = 360 / state.wheelSectors;

            // Add an offset by half the corner of the sector to account for the boundaries between sectors
            const halfSectorOffset = degreesPerSector / 2
            const offsetDegrees = (360 - normalizedDegrees - halfSectorOffset) % 360

            // We calculate the final sector taking into account the offset by half the sector
            let finalSectorIndex = Math.floor(offsetDegrees / degreesPerSector)

            finalSectorIndex = (finalSectorIndex + state.wheelSectors - 1) % state.wheelSectors + 1
            state.wheelPrizeIndex = finalSectorIndex
        }
    },

    actions: {
        async startPrivate({commit, dispatch}) {
            await dispatch('getStream')
        },
        async setRequestTimeout({commit}, status) {
            commit('setPrivateRequestTimeout')
            commit('setPrivateRequestTimer')
            commit(SET_ENTITY, {entity: 'requestStatus', value: status ?? 'pending', module}, {root: true})
        },
        async spinWheel({commit, dispatch, state}) {
            commit('setWheelDegreeStep')
            this.dispatch('SinCamChat/whisperWheelSpin')

            setTimeout(() => {
                commit('setWheelPrize')
                this.dispatch('SinCamChat/whisperWheelPrize', state.wheelMenu[state.wheelPrizeIndex]?.title)
            }, 5000)
        },
        async stopStream({commit, state, dispatch}) {
            console.info('Stopping stream')
            commit(SET_IS_FALSE, 'fullScreen')
            commit(SET_IS_FALSE, 'isActive')

            if (state.showSeconds) {
                console.info('Exists show seconds, stopping private')
                dispatch('stopPrivate')
            }
        },
        async updateStream({commit, state}, data) {
            let updated = {}

            if (data.spy_mode_enabled) {
                updated.spyModeEnabled = data.spy_mode_enabled
            }

            if (Object.keys(updated).length)
                commit(SET_ENTITY, {entity: 'stream', value: {...state.stream, ...updated}, module}, {root: true})
        },
        async getStream({dispatch, commit, rootState, state}) {

            if (state.streamDataIsLoading) {
                console.log('Stream get info double request')
                return
            }

            if (state.showSeconds) {
                dispatch('stopPrivate')
            }

            commit(SET_IS_FALSE, 'isOnline')
            commit(SET_IS_FALSE, 'isStopped')
            commit(SET_IS_FALSE, 'isActive')
            commit(SET_IS_FALSE, 'modelInPrivate')

            commit(SET_IS_TRUE, 'streamDataIsLoading')

            console.info('streamDataIsLoading')

            try {
                const timeoutPromise = new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => {
                        clearTimeout(timeout)
                        commit(SET_ENTITY, {
                            entity: 'lastError',
                            value: 'Cannot receive stream data',
                            module
                        }, {root: true})
                        reject(new Error('Timeout occurred'))

                    }, 9000)
                });

                const streamDataPromise = new Promise(async (resolve, reject) => {
                    try {
                        const streamData = await setStreamData(rootState.Content.contentCreatorData.user_hash)
                        resolve(streamData);
                    } catch (error) {
                        reject(error);
                    }
                });

                console.info('Request stream url')
                // Get stream url
                const {data: streamData} = await Promise.race([streamDataPromise, timeoutPromise])

                if (streamData) {
                    if (!streamData.is_active) {
                        commit(SET_IS_FALSE, 'streamDataIsLoading')
                    } else {
                        commit(SET_IS_TRUE, 'isOnline')
                    }

                    // Setting string params
                    console.info('Setting stream params...')

                    const url = streamData.playback_url ? new URL(streamData.playback_url) : null;

                    let formattedStreamData = {
                        id: streamData.id,
                        isActive: streamData.is_active,
                        type: streamData.type,

                        duration: streamData.duration,
                        thumbnail: streamData.thumbnail,

                        goalsEnabled: streamData.goals_enabled,
                        tipsEnabled: streamData.tips_enabled,

                        createdAt: streamData.created_at,
                        completedAt: streamData.completed_at,
                        spyModeEnabled: streamData.spy_mode_enabled
                    }

                    if (url) {
                        formattedStreamData.url = url.protocol + '//' + url.host + url.pathname
                        formattedStreamData.subscriberId = url.searchParams.get('subscriberId')
                        formattedStreamData.subscriberCode = url.searchParams.get('subscriberCode')
                    }

                    // Set stream data
                    commit(SET_ENTITY, {
                        entity: 'stream',
                        value: formattedStreamData, module
                    }, {root: true})

                    console.info('Checking stream availability...')
                    // Check if stream accessible
                    if (streamData.playback_url && await checkStreamUrl(streamData.playback_url)) {

                        commit(SET_ENTITY, {entity: 'showTitle', value: streamData.details, module}, {root: true})
                        commit(SET_IS_TRUE, 'isActive')

                        // Set tip stats for specified stream
                        await dispatch('setTips', streamData.id)

                        if (streamData.goals_enabled) {
                            await dispatch('setGoals', streamData.id)
                        }

                        // If it's private or spy start show
                        if (streamData.type === 'private') {
                            console.log('Show type is private, checking related request')

                            // Set user show owner id
                            commit(SET_ENTITY, {
                                entity: 'privateUserId',
                                value: streamData.acceptedRequest?.user_id,
                                module
                            }, {root: true})

                            // Start show timer
                            if (streamData.acceptedRequest.user_id === rootState.Auth.user.user_id) {
                                console.info('Setting timers')

                                commit('clearPrivateRequestTimer')
                                setTimeout(() => {
                                    commit('setShowTimer')
                                }, 5000)
                            } else {
                                console.log('Checking if spy mode should be started')
                                if (state.spyIsActive) {
                                    console.info('Setting timers')

                                    commit('clearPrivateRequestTimer')
                                    setTimeout(() => {
                                        commit('setShowTimer')
                                    }, 5000)

                                    commit(SET_IS_FALSE, 'modelInPrivate')
                                } else {
                                    console.info('Stream allowed but spy mode is not active')
                                    commit(SET_IS_FALSE, 'isActive')
                                    commit(SET_IS_TRUE, 'modelInPrivate')
                                    commit(SET_IS_FALSE, 'streamDataIsLoading')
                                }
                            }
                        }

                    } else {
                        // Set model in private flag
                        if (streamData.type === 'private' && !streamData.completed_at) {
                            commit(SET_IS_TRUE, 'modelInPrivate')
                        }
                    }

                    if (streamData.prices) {
                        commit(SET_ENTITY, {entity: 'prices', value: streamData.prices, module}, {root: true})
                    }

                    if (streamData.tips_enabled) {
                        await dispatch('setTipMenu', rootState.Content.contentCreatorData.user_id)
                    }

                    if (streamData.lastRequest && !streamData.lastRequest.accepted_at) {
                        await dispatch('checkLastRequest', streamData.lastRequest)
                    }

                } else {
                    console.info('User stream not found. The specified user has no stream record')
                }
            } catch (error) {
                console.error('Error fetching stream data:', error)
            }

            commit(SET_IS_FALSE, 'streamDataIsLoading')
        },
        async setTips({commit}, streamId) {
            commit(SET_IS_TRUE, 'tipsIsLoading')

            const resp = await getTipStats(streamId)

            if (resp?.data) {
                commit(SET_ENTITY, {entity: 'tips', value: resp.data, module}, {root: true})
            } else {
                console.error('Getting tips failed')
            }

            commit(SET_IS_FALSE, 'tipsIsLoading')
        },
        async setTipMenu({commit}, creatorUserId) {
            const resp = await getTipMenu(creatorUserId)

            if (resp?.data) {
                console.info('Setting tip menu', resp.data)
                commit(SET_ENTITY, {entity: 'tipMenu', value: resp.data, module}, {root: true})
            } else {
                console.error('Getting tip menu failed')
            }
        },
        async setGoals({commit}, streamId) {
            const resp = await getGoals(streamId)

            if (resp?.data) {
                console.info('Setting tip menu', resp.data)
                commit(SET_ENTITY, {entity: 'goals', value: resp.data, module}, {root: true})
            } else {
                console.error('Getting goals failed')
            }
        },
        async sentShowRequest({commit, state, dispatch}, showType) {
            const resp = await requestShow(state.stream.id, showType === 'exclusive')
            dispatch('setRequestTimeout', 'pending')
            console.log(resp?.ok, resp)
        },
        async checkLastRequest({commit, state}, lastRequest) {
            let requestDate = new Date((lastRequest.declined_at ?? lastRequest.created_at) + ' GMT')

            let now = new Date();
            let differenceInSeconds = Math.floor((now - requestDate) / 1000);

            if (differenceInSeconds < state.requestWaitingTime) {
                commit(SET_ENTITY, {
                    entity: 'privateRequestSeconds',
                    value: state.requestWaitingTime - differenceInSeconds,
                    module
                }, {root: true})
                commit(SET_ENTITY, {
                    entity: 'requestStatus',
                    value: lastRequest.declined_at ? 'declined' : 'pending',
                    module
                }, {root: true})
                commit('setPrivateRequestTimer')
            }
        },
        async stopPrivate({state, commit, dispatch}) {
            console.info('Stopping private')

            commit(SET_IS_FALSE, 'isActive')
            commit(SET_IS_FALSE, 'spyIsActive')

            if (state.showSeconds && state.showSeconds > 10) {
                dispatch('payRemainingSeconds')
            }

            if (!state.spyIsActive) {
                console.info('Whispering private exited')
                this.dispatch('SinCamChat/whisperPrivateExited')
            }
        },
        async payRemainingSeconds({state, commit, getters, dispatch}) {
            const remainPayoutseconds = state.showSeconds % 60
            commit('clearShowTimer')

            const showPrice = getters.isSpy ? getters.spyShow?.tokens : getters.privateShow?.tokens

            if (showPrice) {
                const paymentAmount = remainPayoutseconds * (showPrice / 60)

                console.info('Proceed remain payment', remainPayoutseconds, showPrice, paymentAmount.toFixed(2))
                dispatch('balancePayment', paymentAmount.toFixed(2))
            } else {
                console.error('Cannot get show price for remain payment')
            }
        },
        async balancePayment({state, rootState, getters, commit}, amount) {
            if (amount) {
                const paymentData = {
                    amount: amount,
                    creator_user_id: rootState.Content.contentCreatorData.user_id,
                    type: getters.currentShowType,
                    source: 'sincam'
                }

                const response = await balancePayment(paymentData)

                if (response.ok) {
                    console.info('Payment made', paymentData)
                    if (window.$updateBalance) {
                        console.info('Updating balance')
                        window.$updateBalance(response.data.balance)
                    }

                } else {
                    console.error('Problem with payment')
                    commit(SET_ENTITY, {entity: 'lastError', value: 'Problem error occured', module}, {root: true})
                    // Stop show
                }
            } else {
                console.error('Wrong token amount')
            }
        },
        async setSpyShow({state, commit, dispatch}) {
            if (!state.stream?.id) {
                console.log('Empty stream data', state.stream)
            }

            const resp = await requestSpy(state.stream?.id)

            if (resp) {
                commit(SET_IS_TRUE, 'spyIsActive')
                setTimeout(() => dispatch('getStream'), 1000)
            } else {
                console.error('Spy mode is not enabled')
            }
        }
    }
}
