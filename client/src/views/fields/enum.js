/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2021 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

define('views/fields/enum', ['views/fields/base', 'lib!Selectize', 'lib!underscore'], function (Dep, Selectize, _) {

    return Dep.extend({

        type: 'enum',

        listTemplate: 'fields/enum/list',

        listLinkTemplate: 'fields/enum/list-link',

        detailTemplate: 'fields/enum/detail',

        editTemplate: 'fields/enum/edit',

        searchTemplate: 'fields/enum/search',

        translatedOptions: null,

        fetchEmptyValueAsNull: false,

        searchTypeList: ['anyOf', 'noneOf', 'isEmpty', 'isNotEmpty'],

        data: function () {
            var data = Dep.prototype.data.call(this);

            data.translatedOptions = this.translatedOptions;

            var value = this.model.get(this.name);

            if (this.isReadMode() && this.styleMap && (value || value === '')) {
                data.style = this.styleMap[value] || 'default';
            }

            if (this.isReadMode()) {
                if (this.params.displayAsLabel && data.style && data.style !== 'default') {
                    data.class = 'label label-md label';
                } else {
                    data.class = 'text';
                }
            }

            if (
                typeof value !== 'undefined' && value !== null &&  value !== ''
                ||
                value === '' && (
                    value in (this.translatedOptions || {}) && (this.translatedOptions || {})[value] !== ''
                )
            ) {
                data.isNotEmpty = true;
            }

            data.valueIsSet = this.model.has(this.name);

            return data;
        },

        setup: function () {
            if (!this.params.options) {
                var methodName = 'get' + Espo.Utils.upperCaseFirst(this.name) + 'Options';

                if (typeof this.model[methodName] === 'function') {
                    this.params.options = this.model[methodName].call(this.model);
                }
            }

            this.styleMap = this.model.getFieldParam(this.name, 'style') || {};

            this.setupOptions();

            if ('translatedOptions' in this.options) {
                this.translatedOptions = this.options.translatedOptions;
            }

            if ('translatedOptions' in this.params) {
                this.translatedOptions = this.params.translatedOptions;
            }

            this.setupTranslation();

            if (this.translatedOptions === null) {
                this.translatedOptions = this.getLanguage().translate(this.name, 'options', this.model.name) || {};

                if (this.translatedOptions === this.name) {
                    this.translatedOptions = null;
                }
            }

            if (this.params.isSorted && this.translatedOptions) {
                this.params.options = Espo.Utils.clone(this.params.options) || [];

                this.params.options = this.params.options.sort((v1, v2) => {
                     return (this.translatedOptions[v1] || v1).localeCompare(this.translatedOptions[v2] || v2);
                });
            }

            if (this.options.customOptionList) {
                this.setOptionList(this.options.customOptionList);
            }
        },

        setupTranslation: function () {
            if (!this.params.translation) {
                return;
            }

            var translationObj;

            var data = this.getLanguage().data;
            var arr = this.params.translation.split('.');
            var pointer = this.getLanguage().data;

            arr.forEach((key) => {
                if (key in pointer) {
                    pointer = pointer[key];
                    translationObj = pointer;
                }
            });

            this.translatedOptions = null;

            var translatedOptions = {};

            if (!this.params.options) {
                return;
            }

            this.params.options.forEach((item) => {
                if (typeof translationObj === 'object' && item in translationObj) {
                    translatedOptions[item] = translationObj[item];
                }
                else if (
                    typeof translationObj === 'array' &&
                    typeof item === 'integer' &&
                    typeof translationObj[item] !== 'undefined'
                ) {
                    translatedOptions[item.toString()] = translationObj[item];
                }
                else {
                    translatedOptions[item] = item;
                }
            });

            var value = this.model.get(this.name);

            if ((value || value === '') && !(value in translatedOptions)) {
                if (typeof translationObj === 'object' && value in translationObj) {
                    translatedOptions[value] = translationObj[value];
                }
            }

            this.translatedOptions = translatedOptions;
        },

        setupOptions: function () {
        },

        setOptionList: function (optionList) {
            let previousOptions = this.params.options;

            if (!this.originalOptionList) {
                this.originalOptionList = this.params.options;
            }

            this.params.options = Espo.Utils.clone(optionList);

            let isChanged = !_(previousOptions).isEqual(optionList);

            if (this.mode === 'edit' && isChanged) {
                if (this.isRendered()) {
                    this.reRender();

                    if (~(this.params.options || []).indexOf(this.model.get(this.name))) {
                        this.trigger('change');
                    }
                }
                else {
                    this.once('after:render', () => {
                        if (~(this.params.options || []).indexOf(this.model.get(this.name))) {
                            this.trigger('change');
                        }
                    });
                }
            }
        },

        resetOptionList: function () {
            if (this.originalOptionList) {
                this.params.options = Espo.Utils.clone(this.originalOptionList);
            }

            if (this.mode === 'edit') {
                if (this.isRendered()) {
                    this.reRender();
                }
            }
        },

        setupSearch: function () {
            this.events = _.extend({
                'change select.search-type': (e) => {
                    this.handleSearchType($(e.currentTarget).val());
                },
            }, this.events || {});
        },

        handleSearchType: function (type) {
            var $inputContainer = this.$el.find('div.input-container');

            if (~['anyOf', 'noneOf'].indexOf(type)) {
                $inputContainer.removeClass('hidden');
            } else {
                $inputContainer.addClass('hidden');
            }
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.mode === 'search') {
                var $element = this.$element = this.$el.find('.main-element');

                var type = this.$el.find('select.search-type').val();

                this.handleSearchType(type);

                var valueList = this.getSearchParamsData().valueList || this.searchParams.value || [];

                this.$element.val(valueList.join(':,:'));

                var data = [];

                (this.params.options || []).forEach((value) => {
                    var label = this.getLanguage().translateOption(value, this.name, this.scope);

                    if (this.translatedOptions) {
                        if (value in this.translatedOptions) {
                            label = this.translatedOptions[value];
                        }
                    }

                    if (label === '') {
                        return;
                    }

                    data.push({
                        value: value,
                        label: label,
                    });
                });

                this.$element.selectize({
                    options: data,
                    delimiter: ':,:',
                    labelField: 'label',
                    valueField: 'value',
                    highlight: false,
                    searchField: ['label'],
                    plugins: ['remove_button'],
                    score: function (search) {
                        var score = this.getScoreFunction(search);

                        search = search.toLowerCase();

                        return function (item) {
                            if (item.label.toLowerCase().indexOf(search) === 0) {
                                return score(item);
                            }

                            return 0;
                        };
                    },
                });

                this.$el.find('.selectize-dropdown-content').addClass('small');

                this.$el.find('select.search-type').on('change', () => {
                    this.trigger('change');
                });

                this.$element.on('change', () => {
                    this.trigger('change');
                });
            }
        },

        validateRequired: function () {
            if (this.isRequired()) {
                if (!this.model.get(this.name)) {
                    var msg = this.translate('fieldIsRequired', 'messages').replace('{field}', this.getLabelText());

                    this.showValidationMessage(msg);

                    return true;
                }
            }
        },

        fetch: function () {
            var value = this.$element.val();

            if (this.fetchEmptyValueAsNull && !value) {
                value = null;
            }

            var data = {};

            data[this.name] = value;

            return data;
        },

        parseItemForSearch: function (item) {
            return item;
        },

        fetchSearch: function () {
            var type = this.fetchSearchType();

            var list = this.$element.val().split(':,:');

            if (list.length === 1 && list[0] === '') {
                list = [];
            }

            list.forEach((item, i) => {
                list[i] = this.parseItemForSearch(item);
            });

            if (type === 'anyOf') {
                if (list.length === 0) {
                    return {
                        type: 'any',
                        data: {
                            type: 'anyOf',
                            valueList: list
                        },
                    };
                }

                return {
                    type: 'in',
                    value: list,
                    data: {
                        type: 'anyOf',
                        valueList: list
                    },
                };
            }

            if (type === 'noneOf') {
                if (list.length === 0) {
                    return {
                        type: 'any',
                        data: {
                            type: 'noneOf',
                            valueList: list
                        },
                    };
                }

                return {
                    type: 'or',
                    value: [
                        {
                            type: 'isNull',
                            attribute: this.name
                        },
                        {
                            type: 'notIn',
                            value: list,
                            attribute: this.name
                        }
                    ],
                    data: {
                        type: 'noneOf',
                        valueList: list
                    },
                };
            }

            if (type === 'isEmpty') {
                return {
                    type: 'or',
                    value: [
                        {
                            type: 'isNull',
                            attribute: this.name
                        },
                        {
                            type: 'equals',
                            value: '',
                            attribute: this.name
                        }
                    ],
                    data: {
                        type: 'isEmpty'
                    },
                };
            }

            if (type === 'isNotEmpty') {
                return {
                    type: 'and',
                    value: [
                        {
                            type: 'isNotNull',
                            attribute: this.name
                        },
                        {
                            type: 'notEquals',
                            value: '',
                            attribute: this.name
                        }
                    ],
                    data: {
                        type: 'isNotEmpty'
                    },
                };
            }
        },

        getSearchType: function () {
            return this.getSearchParamsData().type || 'anyOf';
        },

    });
});
