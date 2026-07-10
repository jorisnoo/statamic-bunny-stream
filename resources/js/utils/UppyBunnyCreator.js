import { BasePlugin } from '@uppy/core';
import * as api from './api.js';

class UppyBunnyCreator extends BasePlugin {
    constructor(uppy, opts) {
        super(uppy, opts);

        this.id = this.opts.id || 'UppyBunnyCreator';
        this.type = 'modifier';
    }

    create(file) {
        return api.post(this.opts.endpoint, {
            title: file.meta.name,
            thumbnailTime: this.getMsFromTime(file.meta.thumbTime),
        });
    }

    prepareUpload = async (fileIDs) => {
        const promises = fileIDs.map((fileID) => {
            const file = this.uppy.getFile(fileID);

            return this.create(file)
                .then(({ guid, upload }) => {
                    this.uppy.setFileMeta(fileID, { bunnyId: guid, bunnyUpload: upload });
                })
                .catch((err) => {
                    this.uppy.log(err, 'warning');
                });
        });

        const emitPreprocessCompleteForAll = () => {
            fileIDs.forEach((fileID) => {
                const file = this.uppy.getFile(fileID);
                this.uppy.emit('preprocess-complete', file);
            });
        };

        // Why emit `preprocess-complete` for all files at once, instead of
        // above when each is processed?
        // Because it leads to StatusBar showing a weird “upload 6 files” button,
        // while waiting for all the files to complete pre-processing.
        const result_1 = await Promise.all(promises);
        return emitPreprocessCompleteForAll(result_1);
    };

    install() {
        this.uppy.addPreProcessor(this.prepareUpload);
    }

    uninstall() {
        this.uppy.removePreProcessor(this.prepareUpload);
    }

    getMsFromTime(hms) {
        if(typeof hms == "undefined" || hms == null || hms == "") {
            return 0;
        } else {
            if(!this.validateTime(hms)) {
                this.uppy.info(__('Wrong timestamp format for thumbnail creation.'), 'error', 3000);
                throw new Error('Wrong timestamp format for thumbnail creation.');
            }
        }

        let a = hms.split(':');

        let seconds = (+a[0]) * 60 * 60 + (+a[1]) * 60 + (+a[2]);

        return seconds * 1000;
    }

    validateTime(timeString) {
        const timeRegex = /^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/;

        if (!timeRegex.test(timeString)) {
            return false;
        }

        const [hours, minutes, seconds] = timeString.split(':').map(Number);

        if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59 || seconds < 0 || seconds > 59) {
            return false;
        }

        return true;
    }
}

export default UppyBunnyCreator;
