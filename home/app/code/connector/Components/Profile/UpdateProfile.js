

let feed_server_side_details = db.feed_server_side.findOne({ "_id": ObjectId(id) });

let filters = JSON.parse(feed_server_side_details['filter']);
let target_ids = feed_server_side_details['target_ids'];
let profileName = feed_server_side_details['name'];
let profileId = feed_server_side_details['profileId'];
let type = feed_server_side_details['type'];
let partialTemplate = feed_server_side_details['partialTemplate'] ?? null;


class UpdateProfileProduct {
    constructor(product_container) {
        this.setLimit = 2500;
        this.filter_table = db.refine_product;
        this.limitLeft = this.setLimit;
        this.product_container = product_container;
    }

    initializeBulk() {
        this.bulkRefine = this.filter_table.initializeUnorderedBulkOp();
        this.bulkProduct = this.product_container.initializeUnorderedBulkOp();
    }

    initiateTargetUpdate() {

        let data = this.filter_table.find(filters);

        data.forEach((eachRow) => {
            this.updateRefineProduct(eachRow);
            this.updateProductContainer(eachRow);

            if (this.limitLeft <= 0) {
                this.updateMongo();
            }
        });

        if (this.limitLeft < this.setLimit) {
            this.updateMongo();
        }
        db.profile.update({ '_id': profileId }, { '$set': { 'product_update_in_progress': false }
        , '$unset': { 'total_count': 1 }
     });
        db.feed_server_side.deleteOne({ '_id': ObjectId(id) });
    }

    prepareProfileData(profileId, profileName, target_ids, type , partialTemplate) {
        let temp = [];
        target_ids.map((id) => {
            temp.push({
                "profile_id": profileId, 'profile_name': profileName, target_shop_id: id, type: type , "partialTemplate": partialTemplate
            })
        })
        return temp;
    }

    updateRefineProduct(eachRow) {
        this.limitLeft--;
        this.bulkRefine.find(this.findQuery(eachRow))
            .update({ '$set': { 'profile': { "profile_name": profileName, "profile_id": profileId, "type": type , "partialTemplate": partialTemplate } } });
    }

    updateProductContainer(product) {
        let findQueryPR = { "user_id": product.user_id, "shop_id": product.source_shop_id, "source_product_id": product.source_product_id };
        this.bulkProduct.find(findQueryPR).update({
            "$pull": {
                'profile': { 'target_shop_id': { "$in": target_ids } }
            },

        });
        this.bulkProduct.find(findQueryPR).update({
            "$push": {
                'profile': { "$each": this.prepareProfileData(profileId, profileName, target_ids, type ,partialTemplate) }
            }
        });
    }

    findQuery(product) {
        return {
            user_id: product['user_id'],
            container_id: product['container_id'],
            source_product_id: product['source_product_id'],
            source_shop_id: product['source_shop_id'],
            target_shop_id: product['target_shop_id']
        }
    }

    updateMongo() {
        this.bulkRefine.execute();
        this.bulkProduct.execute();
        this.bulkRefine = this.filter_table.initializeUnorderedBulkOp();
        this.bulkProduct = this.product_container.initializeUnorderedBulkOp();
        this.limitLeft = this.setLimit;
    }

}

var connection = new UpdateProfileProduct(db.product_container);

connection.initializeBulk();

connection.initiateTargetUpdate();
