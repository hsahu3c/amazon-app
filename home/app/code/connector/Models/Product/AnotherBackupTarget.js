let feed_server_side_details = db.feed_server_side.findOne({ '_id': ObjectId(id) });

let data = feed_server_side_details['data'];
let source = data['source'];

let target = data['target'];

let user_id = data['user_id'];

let config = feed_server_side_details['config'];


this.setLimit = 5000;
this.filter_table = db.refine_product;
this.limitLeft = this.setLimit;
this.product_container = db.product_container;
this.bulk = this.filter_table.initializeUnorderedBulkOp();
initiateTargetUpdate();



function initiateTargetUpdate() {

    let data = this.product_container.aggregate(getAggregate());

    let t0 = new Date();
    let i = 0;
    data.forEach((eachRow) => {
        let marketplaceInfo = formateMarketplaceArray(eachRow);
        this.limitLeft--;

        this.bulk.find(findQuery(eachRow, true))
            .upsert()
            .update({ '$set': dataToUpdate(eachRow, marketplaceInfo), '$setOnInsert': { 'created_at': new Date() } });

        if (this.limitLeft == 0) {
            updateMongo();
        }
    });
    if (this.limitLeft < this.setLimit) {
        updateMongo();
    }
    let t1 = new Date();

    db.feed_server_side.deleteOne({ '_id': ObjectId(id) });
}

function dataToUpdate(product, marketplaceInfo) {
    return {
        ...findQuery(product),
        target_product_id: product['asin'],
        variant_attributes: product['variant_attributes'],
        tags: product['tags'],
        items: marketplaceInfo['marketplaceInfo'],
        updated_at: new Date(),
        title: marketplaceInfo['parentTitle'],
        ...getAdditionalData(product)
    }
}

function getAdditionalData(product) {
    let additionalData = {};

    config.forEach((ele) => {
        if (product[ele]) {
            additionalData[ele] = product[ele];
        }
    })
    return additionalData
}

function findQuery(product, applyNullTargetOr = false) {
    target_shop_id = applyNullTargetOr ? { target_shop_id: { '$in': [null, target['shopId']] } } : { target_shop_id: target['shopId'] }
    return {
        user_id: product['user_id'],
        container_id: product['container_id'],
        source_product_id: product['source_product_id'],
        source_shop_id: product['shop_id'],
        ...target_shop_id
    }
}

function updateMongo() {
    this.bulk.execute();
    this.bulk = this.filter_table.initializeUnorderedBulkOp();
    this.limitLeft = this.setLimit;
}

function createTargetWithSourceData(sourceArr) {
    let targetArr = JSON.parse(JSON.stringify(sourceArr));
    targetArr['target_marketplace'] = target['marketplace'];
    targetArr['shop_id'] = target['shopId'];
    targetArr['direct'] && delete targetArr['direct'];
    targetArr['source_marketplace'] && delete targetArr['source_marketplace'];
    return targetArr;
}

function formateMarketplaceArray(product) {
    let marketplace = Object.values(product['marketplace'] || []);
    let currenTargetDetaisWithSource = [];
    let target_id = target['shopId'];

    let parentTitle = product['title'];
    marketplace.forEach((value) => {
        let val = currenTargetDetaisWithSource[value['source_product_id']] || { 'target_marketplace': target['marketplace'] };
        if (value['direct'] == true || value['source_marketplace']) {
            currenTargetDetaisWithSource[value['source_product_id']] = { ...createTargetWithSourceData(value), ...val };
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

function getAggregate() {
    let aggregate = [];
    aggregate.push({
        '$match': {
            user_id: user_id,
            shop_id: source.shopId,
            source_marketplace: source.marketplace,
            visibility: 'Catalog and Search',
        }
    });
    return aggregate;
}
