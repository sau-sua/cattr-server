<template>
    <div class="slack-mentions">
        <div v-for="(row, index) in rows" :key="index" class="slack-mentions__row">
            <at-input
                :value="row.email"
                class="slack-mentions__email"
                placeholder="user@example.com"
                @input="setEmail(index, $event)"
            />
            <at-input
                :value="row.slack_user_id"
                class="slack-mentions__slack-id"
                placeholder="U0123ABCD"
                @input="setSlackUserId(index, $event)"
            />
            <at-button class="slack-mentions__remove" @click.prevent="remove(index)">
                <span class="icon icon-x"></span>
            </at-button>
        </div>
        <at-button @click.prevent="add">{{ $t('control.add') }}</at-button>
    </div>
</template>

<script>
    export default {
        props: {
            rows: {
                type: Array,
                default: () => [],
            },
        },
        methods: {
            emitRows(rows) {
                this.$emit('input', rows);
            },
            add() {
                this.emitRows([...this.rows, { email: '', slack_user_id: '' }]);
            },
            remove(index) {
                this.emitRows(this.rows.filter((_, i) => i !== index));
            },
            setEmail(index, value) {
                this.emitRows(this.rows.map((row, i) => (i === index ? { ...row, email: value } : row)));
            },
            setSlackUserId(index, value) {
                this.emitRows(this.rows.map((row, i) => (i === index ? { ...row, slack_user_id: value } : row)));
            },
        },
    };
</script>

<style lang="scss" scoped>
    .slack-mentions {
        &__row {
            display: flex;
            flex-flow: row nowrap;
            margin-bottom: 0.75em;
        }

        &__email,
        &__slack-id {
            flex: 1;
            margin-right: 0.5em;
        }

        &__remove {
            height: 40px;
        }
    }
</style>
