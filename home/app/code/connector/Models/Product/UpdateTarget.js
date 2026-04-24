let feed_server_side_details = db.feed_server_side.findOne({ '_id': ObjectId(id) });

let data = feed_server_side_details['data'];
let source = data['source'];

let target = data['target'];

let user_id = data['user_id'];

let config = feed_server_side_details['config'];

class UpdateTargetMarketplace {

    constructor(product_container) {
        this.setLimit = 1000;
        this.filter_table = db.refine_product;
        this.limitLeft = this.setLimit;
        this.product_container = product_container;
    }

    initializeBulk() {
        this.bulk = this.filter_table.initializeUnorderedBulkOp();
    }


    initiateTargetUpdate() {

        let data = this.product_container.aggregate(this.getAggregate());

        let t0 = new Date();
        let i = 0;
        data.forEach((eachRow) => {
            let marketplaceInfo = this.formateMarketplaceArray(eachRow);
            this.limitLeft--;
            this.bulk.find(this.findQuery(eachRow, false))
                .upsert()
                .update({ '$set': this.dataToUpdate(eachRow, marketplaceInfo), '$setOnInsert': { 'created_at': new Date() } });

            if (this.limitLeft == 0) {
                this.updateMongo();
            }
        });
        if (this.limitLeft < this.setLimit) {
            this.updateMongo();
        }
        let t1 = new Date();

        print(t1 - t0 + ' milisecounds taken');
        db.feed_server_side.deleteOne({ '_id': ObjectId(id) });
    }

    dataToUpdate(product, marketplaceInfo) {
        return {
            ...this.findQuery(product),
            target_product_id: product['asin'],
            variant_attributes: product['variant_attributes'],
            tags: product['tags'],
            items: marketplaceInfo['marketplaceInfo'],
            updated_at: new Date(),
            title: marketplaceInfo['parentTitle'],
            ...this.getAdditionalData(product)
        }
    }

    getAdditionalData(product) {
        let additionalData = {};
        config.forEach((ele) => {
            if (product[ele]) {
                additionalData[ele] = product[ele];
            }
        })
        delete additionalData['profile'];

        if (product.profile) {
            product.profile.map((data) => {
                if (data?.['target_shop_id'] == target['shopId']) {
                    let prepareProfile = {
                        'profile_name': data['profile_name'],
                        'profile_id': data['profile_id'],
                        'type': data['type']
                    }
                    additionalData['profile'] = prepareProfile;
                }
            });
        }
        return additionalData;
    }

    findQuery(product, applyNullTargetOr = false) {
        let target_shop_id = applyNullTargetOr ? { target_shop_id: { '$in': [null, target['shopId']] } } : { target_shop_id: target['shopId'] }
        return {
            user_id: product['user_id'],
            container_id: product['container_id'],
            source_product_id: product['source_product_id'],
            source_shop_id: product['shop_id'],
            ...target_shop_id
        }
    }

    updateMongo() {
        this.bulk.execute();
        this.bulk = this.filter_table.initializeUnorderedBulkOp();
        this.limitLeft = this.setLimit;
    }

    createTargetWithSourceData(sourceArr) {
        let targetArr = JSON.parse(JSON.stringify(sourceArr));
        targetArr['target_marketplace'] = target['marketplace'];
        targetArr['shop_id'] = target['shopId'];
        targetArr['direct'] && delete targetArr['direct'];
        targetArr['source_marketplace'] && delete targetArr['source_marketplace'];
        return targetArr;
    }

    formateMarketplaceArray(product) {
        let marketplace = Object.values(product['marketplace'] || product['marketplacev2'] || []);
        let currenTargetDetaisWithSource = [];
        let target_id = target['shopId'];

        let parentTitle = product['title'];
        marketplace.forEach((value) => {
            let val = currenTargetDetaisWithSource[value['source_product_id']] || { 'target_marketplace': target['marketplace'] };
            if (value['direct'] == true || value['source_marketplace']) {
                currenTargetDetaisWithSource[value['source_product_id']] = { ...this.createTargetWithSourceData(value), ...val };
            }

            if (value['shop_id'] == target_id) {
                currenTargetDetaisWithSource[value['source_product_id']] = { ...val, ...value };
            }
            if (currenTargetDetaisWithSource[value['source_product_id']] && currenTargetDetaisWithSource[value['source_product_id']]['source_product_id'] == product['source_product_id']) {
                parentTitle = currenTargetDetaisWithSource[value['source_product_id']]['title'];
            }
        });

        let updatedMarketplace = Object.values(currenTargetDetaisWithSource);


        return { 'marketplaceInfo': updatedMarketplace, parentTitle: parentTitle };
    }

    getAggregate() {
        let aggregate = [];
        aggregate.push({
            '$match': {
                user_id: user_id,
                shop_id: source.shopId,
                source_marketplace: source.marketplace,
                visibility: 'Catalog and Search',
            }
        });
        // lookup to get the product details
        aggregate.push({
            $lookup: {
                from: "product_container", // target collection
                let: { srcContainerId: "$container_id" },
                pipeline: [
                    {
                        $match: {
                            $expr: {
                                $and: [
                                    { $eq: ["$user_id", user_id] }, // static shop_id
                                    {
                                        $or: [
                                            { $eq: ["$shop_id", source['shopId']] },
                                            { $eq: ["$shop_id", target['shopId']] }
                                        ]
                                    },        
                                    // { $eq: ["$shop_id", target['shopId']] },               // static shop_id
                                    { $eq: ["$container_id", "$$srcContainerId"] }, // match main & joined
                                ]
                            }
                        }
                    },
                    {
                        $project: { _id: 0, category_settings: 0, description: 0, marketplace: 0 }
                    }
                ],
                as: "marketplacev2"
            }
        });
        // unwind the container array
        // aggregate.push({ '$unwind': { 'path': '$container', 'preserveNullAndEmptyArrays': true } });
        return aggregate;
    }
}
// print('connected');

// printjson(t);

var connection = new UpdateTargetMarketplace(db.product_container);

connection.initializeBulk();

connection.initiateTargetUpdate();



// print(par);

// printjson(db.product_container.count());